<?php

namespace Spawnflow\Tests\Fixtures;

use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;

/**
 * Misconfigured-on-purpose: a default-scoped relation to a table (users)
 * that has no ownership column. The options endpoint must fail CLOSED.
 */
class LookupFields extends FieldSet
{
    public static function fields(): array
    {
        return [
            Field::belongsTo('user_id', User::class)->display('name'),
        ];
    }
}
