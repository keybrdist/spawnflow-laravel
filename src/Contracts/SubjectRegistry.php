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
     * Get all registered subject aliases.
     *
     * @return array<string, class-string<Model>>
     */
    public function all(): array;
}
