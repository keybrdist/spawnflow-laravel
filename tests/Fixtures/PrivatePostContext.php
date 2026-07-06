<?php

namespace Spawnflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Spawnflow\Contracts\FieldContext;

/**
 * Deny-capable context: owners resolve a case, everyone else resolves null
 * (DENY). Exercises the null-return branch of FieldContext::resolve().
 */
enum PrivatePostContext: string implements FieldContext
{
    case Owner = 'owner';

    public static function resolve(User $user, Model $record): ?static
    {
        return $user->id === $record->owner_id ? self::Owner : null;
    }

    public function editableFields(): array
    {
        return ['title', 'body', 'status'];
    }

    public function validation(): array
    {
        return [
            'title' => 'required|string|max:255',
        ];
    }

    public function visibleFields(): array
    {
        return ['id', 'title', 'body', 'status'];
    }
}
