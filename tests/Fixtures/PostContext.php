<?php

namespace Spawnflow\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Spawnflow\Contracts\FieldContext;

enum PostContext: string implements FieldContext
{
    case OwnerDraft = 'owner:draft';
    case OwnerPublished = 'owner:published';
    case Viewer = 'viewer';

    public static function resolve(User $user, Model $record): static
    {
        return match (true) {
            $user->id === $record->owner_id && $record->status === 'draft' => self::OwnerDraft,
            $user->id === $record->owner_id => self::OwnerPublished,
            default => self::Viewer,
        };
    }

    public function editableFields(): array
    {
        return match ($this) {
            self::OwnerDraft => ['title', 'body', 'status'],
            self::OwnerPublished => ['title'],
            self::Viewer => [],
        };
    }

    public function validation(): array
    {
        return match ($this) {
            self::OwnerDraft => [
                'title' => 'required|string|max:255',
                'body' => 'nullable|string',
                'status' => 'in:draft,published',
            ],
            self::OwnerPublished => [
                'title' => 'required|string|max:255',
            ],
            self::Viewer => [],
        };
    }

    public function visibleFields(): array
    {
        return match ($this) {
            self::OwnerDraft, self::OwnerPublished => [
                'id', 'title', 'body', 'status', 'owner_id', 'created_at', 'updated_at',
            ],
            self::Viewer => [
                'id', 'title', 'status',
            ],
        };
    }
}
