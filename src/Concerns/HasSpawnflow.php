<?php

namespace Spawnflow\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Optional trait for Eloquent models used with Spawnflow.
 *
 * Provides a convenient ownership scope that matches
 * the configured ownership column.
 */
trait HasSpawnflow
{
    /**
     * Scope a query to records owned by a specific user.
     */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        $column = config('spawnflow.ownership_column', 'ownerId');

        return $query->where($column, $userId);
    }
}
