<?php

namespace Spawnflow\Validation;

use Spawnflow\Contracts\FieldContext;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;

/**
 * The server-side half of the validation authority: resolves the effective
 * raw Laravel rules for a subject from its FieldSet descriptors and the
 * active FieldContext case.
 *
 * Precedence per field: the context case's validation() entry replaces the
 * field's base rules; rules implied by the descriptor (type, enum
 * membership, relation existence, nullability) are appended unless a rule
 * of the same name is already present. Mirrors the schema contract, so what
 * the frontend is told is exactly what the server enforces.
 */
class RuleResolver
{
    public function __construct(protected SubjectRegistry $registry) {}

    /**
     * Effective rules for a subject. With a context, scoped to its editable
     * fields; without one, every declared field. Subjects without a
     * FieldSet fall back to the context's validation() as-is.
     *
     * @return array<string, array<int, mixed>|string>
     */
    public function for(string $alias, ?FieldContext $context): array
    {
        /** @var class-string<FieldSet>|null $fieldSet */
        $fieldSet = $this->registry->fieldsFor($alias);
        $overrides = $context?->validation() ?? [];

        if ($fieldSet === null) {
            return $overrides;
        }

        $names = $context ? $context->editableFields() : array_keys($fieldSet::all());

        $rules = [];
        foreach ($names as $name) {
            $field = $fieldSet::field($name);

            if ($field === null) {
                if (isset($overrides[$name])) {
                    $rules[$name] = $overrides[$name];
                }

                continue;
            }

            $rules[$name] = $this->compose($field, $overrides[$name] ?? null);
        }

        return $rules;
    }

    /**
     * A field's effective raw rules: override or base, plus applicable
     * descriptor-implied rules.
     *
     * @param  string|array<int, mixed>|null  $override
     * @return array<int, mixed>
     */
    protected function compose(Field $field, string|array|null $override): array
    {
        $rules = $override ?? $field->getRules();
        $rules = is_string($rules) ? ($rules === '' ? [] : explode('|', $rules)) : $rules;

        $present = [];
        foreach ($rules as $rule) {
            if (is_string($rule)) {
                $present[mb_strtolower(strtok($rule, ':'))] = true;
            }
        }

        foreach ($field->impliedRawRules() as $implied) {
            $name = mb_strtolower(strtok($implied, ':'));

            if (isset($present[$name])) {
                continue;
            }

            if ($name === 'nullable' && isset($present['required'])) {
                continue;
            }

            $rules[] = $implied;
        }

        return $rules;
    }
}
