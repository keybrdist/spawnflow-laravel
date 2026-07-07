<?php

namespace Spawnflow\Schema;

use Spawnflow\Exceptions\InvalidEligibilityException;

/**
 * A subject's field descriptors — one class per subject, registered in
 * config('spawnflow.fields'). The single owned definition of what each
 * field is; contexts reference fields by name, the schema layer joins
 * names to descriptors.
 */
abstract class FieldSet
{
    /** @var array<class-string<static>, array<string, Field>> */
    private static array $resolved = [];

    /** @var array<class-string<static>, array<string, Group>> */
    private static array $resolvedGroups = [];

    /**
     * Declare the subject's fields.
     *
     * @return list<Field>
     */
    abstract public static function fields(): array;

    /**
     * Declare the subject's field groups (sections / wizard steps), in
     * render order. Fields not named by any group render ungrouped.
     *
     * @return list<Group>
     */
    public static function groups(): array
    {
        return [];
    }

    /**
     * All fields keyed by name.
     *
     * @return array<string, Field>
     */
    public static function all(): array
    {
        return self::$resolved[static::class] ??= array_column(
            array_map(fn (Field $field) => [$field->name, $field], static::fields()),
            1,
            0,
        );
    }

    public static function field(string $name): ?Field
    {
        return static::all()[$name] ?? null;
    }

    /**
     * All groups keyed by name, membership validated: every member must
     * be a declared field, and a field belongs to at most one group.
     *
     * @return array<string, Group>
     */
    public static function allGroups(): array
    {
        if (isset(self::$resolvedGroups[static::class])) {
            return self::$resolvedGroups[static::class];
        }

        $declared = static::all();
        $owner = [];
        $groups = [];

        foreach (static::groups() as $group) {
            foreach ($group->fields as $member) {
                if (! isset($declared[$member])) {
                    throw new InvalidEligibilityException(
                        'Group \''.$group->name."' names undeclared field '{$member}'.",
                    );
                }
                if (isset($owner[$member])) {
                    throw new InvalidEligibilityException(
                        "Field '{$member}' belongs to both groups '{$owner[$member]}' and '{$group->name}' — a field may belong to at most one group.",
                    );
                }
                $owner[$member] = $group->name;
            }

            $groups[$group->name] = $group;
        }

        return self::$resolvedGroups[static::class] = $groups;
    }

    /**
     * The group a field belongs to, if any.
     */
    public static function groupFor(string $field): ?Group
    {
        foreach (static::allGroups() as $group) {
            if (in_array($field, $group->fields, true)) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Raw Laravel validation rules per field, for fields that declare any.
     *
     * @return array<string, string|array>
     */
    public static function rules(): array
    {
        $rules = [];
        foreach (static::all() as $name => $field) {
            if ($field->getRules() !== [] && $field->getRules() !== '') {
                $rules[$name] = $field->getRules();
            }
        }

        return $rules;
    }
}
