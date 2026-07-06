<?php

namespace Spawnflow\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;

interface FieldContext
{
    /**
     * Resolve the permission context from the authenticated user and the target record.
     *
     * The resolved context determines which fields are editable, which validation
     * rules apply, and which fields are visible in the response.
     *
     * Return null to DENY: the user gets no context for this record at all.
     * Write paths reject with 403; the schema endpoint responds as if the
     * record does not exist, so resolution cannot be used as a cross-tenant
     * existence probe. Enums that grant everyone something (e.g. a Viewer
     * case) simply never return null.
     */
    public static function resolve(User $user, Model $record): ?static;

    /**
     * Fields this context is allowed to write.
     *
     * @return string[]
     */
    public function editableFields(): array;

    /**
     * Laravel validation rules scoped to this context.
     *
     * @return array<string, string|array>
     */
    public function validation(): array;

    /**
     * Fields visible in API responses for this context.
     *
     * @return string[]
     */
    public function visibleFields(): array;
}
