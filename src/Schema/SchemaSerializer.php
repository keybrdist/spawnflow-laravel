<?php

namespace Spawnflow\Schema;

use Illuminate\Support\Str;
use Spawnflow\Contracts\FieldContext;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Exceptions\InvalidEligibilityException;

/**
 * The single serializer behind the schema contract (v1).
 *
 * Both the live schema endpoint and the static generator emit through this
 * class, so the two can never drift. See docs/schema-contract.md for the
 * contract specification.
 */
class SchemaSerializer
{
    public const VERSION = '1';

    public function __construct(protected SubjectRegistry $registry) {}

    // ---------------------------------------------------------------
    // Top-level shapes
    // ---------------------------------------------------------------

    /**
     * The resolved-variant schema for a specific record:
     * one context case, per-field editable/visible flags and effective
     * rules, plus rule-eligibility verdicts evaluated against the
     * record's data.
     *
     * @param  array<string, mixed>  $data  The record's current values (defaults on create).
     */
    public function resolved(string $alias, FieldContext $context, array $data = []): array
    {
        $this->assertEligibilityConsistent($alias, [$context]);

        $editable = array_flip($context->editableFields());
        $visible = array_flip($context->visibleFields());
        $overrides = $context->validation();

        // The resolved schema exposes only what this variant grants:
        // the union of its editable and visible fields.
        $names = array_values(array_unique(array_merge(
            $context->editableFields(),
            $context->visibleFields(),
        )));

        $fields = [];
        foreach ($names as $name) {
            $field = $this->fieldFor($alias, $name);
            $isEditable = isset($editable[$name]);

            $descriptor = $this->describeField($field, $alias) + [
                'editable' => $isEditable,
                'visible' => isset($visible[$name]),
            ];

            if ($isEditable) {
                $descriptor['rules'] = $this->effectiveRules($field, $overrides[$name] ?? null);
            }

            $fields[$name] = $descriptor;
        }

        return [
            'spawnflow' => self::VERSION,
            'resource' => $alias,
            'context' => $this->contextValue($context),
            'fields' => $fields,
        ] + $this->resolvedEligibility($alias, $data);
    }

    /**
     * The all-variants schema for a subject: field descriptors once,
     * plus per-variant editability, visibility, and effective rules —
     * a discriminated union over the context's cases.
     *
     * @param  class-string<FieldContext>  $contextClass
     */
    public function variants(string $alias, string $contextClass): array
    {
        $cases = $contextClass::cases();

        $this->assertEligibilityConsistent($alias, $cases);

        $variants = [];
        foreach ($cases as $case) {
            $overrides = $case->validation();

            $rules = [];
            foreach ($case->editableFields() as $name) {
                $rules[$name] = $this->effectiveRules(
                    $this->fieldFor($alias, $name),
                    $overrides[$name] ?? null,
                );
            }

            $variants[] = [
                'context' => $this->contextValue($case),
                'editable_fields' => $case->editableFields(),
                'visible_fields' => $case->visibleFields(),
                'rules' => $rules,
            ];
        }

        return [
            'spawnflow' => self::VERSION,
            'resource' => $alias,
            'fields' => $this->describeFields($alias, $cases),
            'variants' => $variants,
        ] + $this->resolvedEligibility($alias, []);
    }

    /**
     * Schema for a subject with no FieldContext: every declared field is
     * editable and visible with its base rules (owner-default behavior).
     */
    public function defaultSchema(string $alias): array
    {
        $this->assertEligibilityConsistent($alias, []);

        $fieldSet = $this->registry->fieldsFor($alias);

        $fields = [];
        foreach ($fieldSet ? array_keys($fieldSet::all()) : [] as $name) {
            $field = $this->fieldFor($alias, $name);
            $fields[$name] = $this->describeField($field, $alias) + [
                'editable' => true,
                'visible' => true,
                'rules' => $this->effectiveRules($field, null),
            ];
        }

        return [
            'spawnflow' => self::VERSION,
            'resource' => $alias,
            'context' => 'default',
            'fields' => $fields,
        ] + $this->resolvedEligibility($alias, []);
    }

    // ---------------------------------------------------------------
    // Field descriptors
    // ---------------------------------------------------------------

    /**
     * A field's identity descriptor — type, widget, label, enum options,
     * relation semantics. Permission flags and rules are contributed by
     * the variant layer, not here.
     */
    public function describeField(Field $field, ?string $alias = null): array
    {
        $descriptor = [
            'type' => $field->type->value,
            'widget' => $field->getWidget(),
            'label' => $field->getLabel(),
        ];

        if ($field->isNullable()) {
            $descriptor['nullable'] = true;
        }

        if ($field->getDefault() !== null) {
            $descriptor['default'] = $field->getDefault();
        }

        if ($field->getWireFormat() !== null) {
            $descriptor['wire'] = $field->getWireFormat();
        }

        if ($field->isWriteOnly()) {
            $descriptor['writeOnly'] = true;
        }

        if ($field->getEligibilityRules() !== []) {
            if ($field->isServerResolved()) {
                // The condition stays server-side; clients get only the
                // computed verdict (the `resolved` key) and re-fetch to
                // refresh it.
                $descriptor['serverResolved'] = true;
            } else {
                $descriptor['eligibility'] = array_map(
                    fn ($rule) => $rule->toArray(),
                    $field->getEligibilityRules(),
                );
            }
        }

        if ($field->type === FieldType::Enum) {
            $descriptor['options'] = $field->getOptions();
        }

        if ($field->type === FieldType::Relation) {
            $descriptor['relation'] = [
                'subject' => $this->registry->aliasFor($field->getRelatedModel()),
                'display' => $field->getDisplayColumn(),
                'searchable' => $field->isSearchable(),
                'multiple' => $field->isMultiple(),
            ];

            if ($alias !== null && config('spawnflow.schema_routes', false)) {
                $descriptor['relation']['options_url'] = "/spawnflow/options/{$alias}/{$field->name}";
            }
        }

        return $descriptor;
    }

    /**
     * Effective structured rules for a field: a per-context override when
     * given, otherwise the field's own rules — plus rules implied by the
     * field's type, enum options, relation, and nullability.
     *
     * @param  string|array<int, mixed>|null  $override
     * @return list<array{rule: string, params?: array, serverOnly?: bool}>
     */
    public function effectiveRules(Field $field, string|array|null $override): array
    {
        $rules = RuleSerializer::serialize($override ?? $field->getRules());
        $present = array_flip(array_column($rules, 'rule'));

        $implied = [];
        foreach (RuleSerializer::serialize($field->impliedRawRules()) as $entry) {
            if (static::impliedApplies($entry['rule'], $present)) {
                $implied[] = $entry;
            }
        }

        return array_merge($rules, $implied);
    }

    /**
     * Whether an implied rule should be appended given the rules already
     * present by name.
     *
     * @param  array<string, int>  $present
     */
    protected static function impliedApplies(string $name, array $present): bool
    {
        if (isset($present[$name])) {
            return false;
        }

        // An explicit enum rule already covers membership.
        if ($name === 'in' && isset($present['enum'])) {
            return false;
        }

        // A required field must not gain implied nullability.
        if ($name === 'nullable' && isset($present['required'])) {
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------
    // Eligibility
    // ---------------------------------------------------------------

    /**
     * Groups plus server-computed rule verdicts, evaluated against the
     * given data completed with field defaults — so create-time (empty
     * data) resolves against defaults, exactly what the client's initial
     * form state sees. Field verdicts are FINAL (own rules AND group).
     *
     * @param  array<string, mixed>  $data
     * @return array{groups?: list<array>, resolved?: array<string, array{visible: bool, enabled: bool}>, resolved_groups?: array<string, array{visible: bool, enabled: bool}>}
     */
    protected function resolvedEligibility(string $alias, array $data): array
    {
        $fieldSet = $this->registry->fieldsFor($alias);
        if ($fieldSet === null) {
            return [];
        }

        $out = [];

        if ($fieldSet::allGroups() !== []) {
            $out['groups'] = array_values(array_map(
                fn (Group $group) => $group->toArray(),
                $fieldSet::allGroups(),
            ));
        }

        $resolved = Eligibility::fieldVerdicts($fieldSet, $data);
        if ($resolved !== []) {
            $out['resolved'] = $resolved;
        }

        $resolvedGroups = Eligibility::groupVerdicts($fieldSet, $data);
        if ($resolvedGroups !== []) {
            $out['resolved_groups'] = $resolvedGroups;
        }

        return $out;
    }

    /**
     * The declaration-time guard: every rule must reference declared
     * fields, and — for client-evaluated rules — fields the referencing
     * field's variant can SEE, or the client would re-evaluate against
     * values it never receives. Mark the field ->serverResolved() to
     * opt out of client re-evaluation instead.
     *
     * @param  list<FieldContext>  $cases
     */
    protected function assertEligibilityConsistent(string $alias, array $cases): void
    {
        $fieldSet = $this->registry->fieldsFor($alias);
        if ($fieldSet === null) {
            return;
        }

        // Materializing groups validates membership (declared fields,
        // single ownership) as a side effect.
        $groups = $fieldSet::allGroups();

        foreach ($fieldSet::all() as $name => $field) {
            $this->assertRuleReferences(
                $alias,
                "field '{$name}'",
                Eligibility::referencedFields($field),
                $field->isServerResolved(),
                exposedWhen: fn (FieldContext $case) => in_array(
                    $name,
                    array_merge($case->editableFields(), $case->visibleFields()),
                    true,
                ),
                fieldSet: $fieldSet,
                cases: $cases,
            );
        }

        foreach ($groups as $group) {
            $references = [];
            foreach ($group->getEligibilityRules() as $rule) {
                $references = array_merge($references, $rule->references());
            }

            $this->assertRuleReferences(
                $alias,
                "group '{$group->name}'",
                array_values(array_unique($references)),
                $group->isServerResolved(),
                // A group's rule matters to any variant exposing ANY member.
                exposedWhen: fn (FieldContext $case) => array_intersect(
                    $group->fields,
                    array_merge($case->editableFields(), $case->visibleFields()),
                ) !== [],
                fieldSet: $fieldSet,
                cases: $cases,
            );
        }
    }

    /**
     * @param  list<string>  $references
     * @param  \Closure(FieldContext): bool  $exposedWhen
     * @param  class-string<FieldSet>  $fieldSet
     * @param  list<FieldContext>  $cases
     */
    protected function assertRuleReferences(
        string $alias,
        string $subject,
        array $references,
        bool $serverResolved,
        \Closure $exposedWhen,
        string $fieldSet,
        array $cases,
    ): void {
        if ($references === []) {
            return;
        }

        $declared = array_flip(array_keys($fieldSet::all()));

        foreach ($references as $reference) {
            if (! isset($declared[$reference])) {
                throw new InvalidEligibilityException(
                    "Eligibility rule on {$subject} of '{$alias}' references undeclared field '{$reference}'.",
                );
            }
        }

        if ($serverResolved) {
            return;
        }

        foreach ($cases as $case) {
            if (! $exposedWhen($case)) {
                continue;
            }

            $visible = array_flip($case->visibleFields());
            foreach ($references as $reference) {
                if (! isset($visible[$reference])) {
                    throw new InvalidEligibilityException(
                        "Eligibility rule on {$subject} of '{$alias}' references '{$reference}', which variant '{$this->contextValue($case)}' cannot see. Mark it ->serverResolved() or expose '{$reference}' in that variant.",
                    );
                }
            }
        }
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * Descriptor map for every field the subject exposes.
     *
     * @param  list<FieldContext>  $cases
     */
    protected function describeFields(string $alias, array $cases): array
    {
        $fields = [];
        foreach ($this->fieldNames($alias, $cases) as $name) {
            $fields[$name] = $this->describeField($this->fieldFor($alias, $name), $alias);
        }

        return $fields;
    }

    /**
     * Field names the subject exposes: the registered FieldSet when one
     * exists, otherwise the union of editable and visible names across
     * the given context cases.
     *
     * @param  list<FieldContext>  $cases
     * @return list<string>
     */
    protected function fieldNames(string $alias, array $cases): array
    {
        if ($fieldSet = $this->registry->fieldsFor($alias)) {
            $names = array_keys($fieldSet::all());
        } else {
            $names = [];
        }

        foreach ($cases as $case) {
            $names = array_merge($names, $case->editableFields(), $case->visibleFields());
        }

        return array_values(array_unique($names));
    }

    /**
     * The declared descriptor for a field, or a minimal inferred one when
     * the subject has no FieldSet (or the context names an undeclared field).
     */
    protected function fieldFor(string $alias, string $name): Field
    {
        $fieldSet = $this->registry->fieldsFor($alias);

        return ($fieldSet !== null ? $fieldSet::field($name) : null) ?? Field::string($name);
    }

    protected function contextValue(FieldContext $context): string
    {
        if ($context instanceof \BackedEnum) {
            return (string) $context->value;
        }

        if ($context instanceof \UnitEnum) {
            return $context->name;
        }

        return Str::snake(class_basename($context));
    }
}
