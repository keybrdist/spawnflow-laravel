<?php

namespace Spawnflow\Schema;

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

    /**
     * Declare the subject's fields.
     *
     * @return list<Field>
     */
    abstract public static function fields(): array;

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
