<?php

namespace Spawnflow\Schema;

use Illuminate\Support\Str;
use Spawnflow\Contracts\FieldContext;
use Spawnflow\Contracts\SubjectRegistry;

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
     * one context case, per-field editable/visible flags and effective rules.
     */
    public function resolved(string $alias, FieldContext $context): array
    {
        $editable = array_flip($context->editableFields());
        $visible = array_flip($context->visibleFields());
        $overrides = $context->validation();

        $fields = [];
        foreach ($this->fieldNames($alias, [$context]) as $name) {
            $field = $this->fieldFor($alias, $name);
            $isEditable = isset($editable[$name]);

            $descriptor = $this->describeField($field) + [
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
        ];
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
        ];
    }

    /**
     * Schema for a subject with no FieldContext: every declared field is
     * editable and visible with its base rules (owner-default behavior).
     */
    public function defaultSchema(string $alias): array
    {
        $fieldSet = $this->registry->fieldsFor($alias);

        $fields = [];
        foreach ($fieldSet ? array_keys($fieldSet::all()) : [] as $name) {
            $field = $this->fieldFor($alias, $name);
            $fields[$name] = $this->describeField($field) + [
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
        ];
    }

    // ---------------------------------------------------------------
    // Field descriptors
    // ---------------------------------------------------------------

    /**
     * A field's identity descriptor — type, widget, label, enum options,
     * relation semantics. Permission flags and rules are contributed by
     * the variant layer, not here.
     */
    public function describeField(Field $field): array
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

        foreach ($field->type->impliedRules() as $name) {
            if (! isset($present[$name])) {
                $implied[] = ['rule' => $name];
            }
        }

        if ($field->type === FieldType::Enum && ! isset($present['in']) && ! isset($present['enum'])) {
            $implied[] = ['rule' => 'in', 'params' => $field->getOptionValues()];
        }

        if ($field->type === FieldType::Relation && ! isset($present['exists'])) {
            $implied[] = ['rule' => 'exists', 'serverOnly' => true];
        }

        if ($field->isNullable() && ! isset($present['nullable']) && ! isset($present['required'])) {
            $implied[] = ['rule' => 'nullable'];
        }

        return array_merge($rules, $implied);
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
            $fields[$name] = $this->describeField($this->fieldFor($alias, $name));
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
