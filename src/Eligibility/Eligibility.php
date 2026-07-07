<?php

namespace Spawnflow\Eligibility;

use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;

/**
 * Combines a field's eligibility rules into per-axis verdicts, and
 * resolves which of a FieldSet's fields are rule-ineligible for a given
 * data state — the single definition the schema serializer (resolved
 * booleans on the wire) and the write path (Flow::validate/save
 * stripping) both consume.
 *
 * Composition: verdicts AND together per axis; a field with no rules on
 * an axis defaults to eligible. Data is always the FULL declared field
 * map — absent fields present as null (or their default) — so both
 * runtimes evaluate against the same shape.
 */
final class Eligibility
{
    /**
     * Per-axis verdict for a set of rules against a data state.
     *
     * @param  list<Rule>  $rules
     * @param  array<string, mixed>  $data
     * @return array{visible: bool, enabled: bool}
     */
    public static function resolve(array $rules, array $data): array
    {
        $visible = true;
        $enabled = true;

        foreach ($rules as $rule) {
            $visible = $visible && ($rule->visible($data) ?? true);
            $enabled = $enabled && ($rule->enabled($data) ?? true);
        }

        return ['visible' => $visible, 'enabled' => $enabled];
    }

    /**
     * Final per-field verdicts for every field governed by rules — its
     * own or its group's. Group composition is AND: a hidden group hides
     * its members regardless of their own rules; a disabled group
     * disables them.
     *
     * @param  class-string<FieldSet>  $fieldSet
     * @param  array<string, mixed>  $data  Partial field values; completed with defaults/null.
     * @return array<string, array{visible: bool, enabled: bool}>
     */
    public static function fieldVerdicts(string $fieldSet, array $data): array
    {
        $complete = self::complete($fieldSet, $data);
        $groups = self::groupVerdicts($fieldSet, $data);

        $verdicts = [];
        foreach ($fieldSet::all() as $name => $field) {
            $group = $fieldSet::groupFor($name);
            $groupState = $group !== null ? ($groups[$group->name] ?? null) : null;

            if ($field->getEligibilityRules() === [] && $groupState === null) {
                continue;
            }

            $state = self::resolve($field->getEligibilityRules(), $complete);

            if ($groupState !== null) {
                $state = [
                    'visible' => $state['visible'] && $groupState['visible'],
                    'enabled' => $state['enabled'] && $groupState['enabled'],
                ];
            }

            $verdicts[$name] = $state;
        }

        return $verdicts;
    }

    /**
     * Per-group verdicts, for groups that carry rules.
     *
     * @param  class-string<FieldSet>  $fieldSet
     * @param  array<string, mixed>  $data
     * @return array<string, array{visible: bool, enabled: bool}>
     */
    public static function groupVerdicts(string $fieldSet, array $data): array
    {
        $complete = self::complete($fieldSet, $data);

        $verdicts = [];
        foreach ($fieldSet::allGroups() as $name => $group) {
            if ($group->getEligibilityRules() !== []) {
                $verdicts[$name] = self::resolve($group->getEligibilityRules(), $complete);
            }
        }

        return $verdicts;
    }

    /**
     * Names of the FieldSet's fields whose rules — own or group — make
     * them ineligible (hidden or disabled) for the given data state.
     *
     * @param  class-string<FieldSet>  $fieldSet
     * @param  array<string, mixed>  $data  Partial field values; completed with defaults/null.
     * @return list<string>
     */
    public static function ineligible(string $fieldSet, array $data): array
    {
        $ineligible = [];
        foreach (self::fieldVerdicts($fieldSet, $data) as $name => $state) {
            if (! $state['visible'] || ! $state['enabled']) {
                $ineligible[] = $name;
            }
        }

        return $ineligible;
    }

    /**
     * The full evaluation data shape: every declared field present, from
     * the given values, falling back to the field's default, then null.
     *
     * @param  class-string<FieldSet>  $fieldSet
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function complete(string $fieldSet, array $data): array
    {
        $complete = [];
        foreach ($fieldSet::all() as $name => $field) {
            $complete[$name] = array_key_exists($name, $data) ? $data[$name] : $field->getDefault();
        }

        return $complete;
    }

    /**
     * Field names referenced across all of a field's rules.
     *
     * @return list<string>
     */
    public static function referencedFields(Field $field): array
    {
        $refs = [];
        foreach ($field->getEligibilityRules() as $rule) {
            $refs = array_merge($refs, $rule->references());
        }

        return array_values(array_unique($refs));
    }
}
