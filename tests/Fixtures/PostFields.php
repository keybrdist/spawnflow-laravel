<?php

namespace Spawnflow\Tests\Fixtures;

use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;

class PostFields extends FieldSet
{
    public static function fields(): array
    {
        return [
            Field::string('title')->rules('required|string|max:255'),
            Field::text('body')->nullable(),
            Field::enum('status', PostStatus::class),
            Field::int('owner_id')->label('Owner'),
        ];
    }
}
