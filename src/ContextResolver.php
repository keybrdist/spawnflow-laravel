<?php

namespace Spawnflow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Spawnflow\Contracts\FieldContext;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Exceptions\OwnershipException;

/**
 * Resolves the active FieldContext case for a (user, record) pair.
 *
 * The single definition of context resolution — used by Flow::fields(),
 * the schema endpoint, and the FormRequest bridge. On create (no record),
 * a synthetic record is built from the input with the caller's ownership
 * so the context enum resolves against the intended state.
 */
class ContextResolver
{
    public function __construct(protected SubjectRegistry $registry) {}

    /**
     * @param  class-string<FieldContext>|null  $contextClass  Explicit class, or null to resolve from config.
     */
    public function resolve(
        string $alias,
        User $user,
        ?Model $record,
        array $input = [],
        ?string $contextClass = null,
    ): ?FieldContext {
        $contextClass ??= $this->registry->contextFor($alias);

        if ($contextClass === null) {
            return null;
        }

        if ($record === null) {
            $ownershipColumn = config('spawnflow.ownership_column', 'ownerId');
            $userKey = config('spawnflow.user_key', 'id');

            $record = $this->registry->resolve($alias)->newInstance();
            $record->forceFill($input);
            $record->{$ownershipColumn} = $user->{$userKey};
        }

        $context = $contextClass::resolve($user, $record);

        // A configured enum returning null is an explicit DENY — distinct from
        // "no context configured" (the null return above). Throw so callers
        // that treat a null context as "no field restrictions" (Flow::fields,
        // the FormRequest bridge) cannot silently fail open.
        if ($context === null) {
            throw new OwnershipException;
        }

        return $context;
    }
}
