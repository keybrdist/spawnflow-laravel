<?php

namespace Spawnflow\Contracts;

use Illuminate\Database\Eloquent\Model;

interface SubjectRegistry
{
    /**
     * Resolve a subject alias to an Eloquent model instance.
     *
     * @throws \Spawnflow\Exceptions\UnresolvableSubjectException
     */
    public function resolve(string $alias): Model;

    /**
     * Resolve a subject alias to its FieldContext enum class, if one exists.
     *
     * Returns null if the subject has no context enum (falls back to default behavior).
     *
     * @return class-string<FieldContext>|null
     */
    public function contextFor(string $alias): ?string;

    /**
     * Resolve a subject alias to its FieldSet class, if one is registered.
     *
     * Returns null if the subject has no field descriptors (schema output
     * falls back to minimal inferred descriptors).
     *
     * @return class-string<\Spawnflow\Schema\FieldSet>|null
     */
    public function fieldsFor(string $alias): ?string;

    /**
     * Reverse lookup: the registered alias for a model class, if any.
     *
     * @param  class-string<Model>|null  $modelClass
     */
    public function aliasFor(?string $modelClass): ?string;

    /**
     * Get all registered subject aliases.
     *
     * @return array<string, class-string<Model>>
     */
    public function all(): array;
}
