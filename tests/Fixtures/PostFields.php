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
            // Body is editable only while the post is a draft — exercises
            // the eligibility-rule axis, orthogonal to context variants.
            Field::text('body')->nullable()
                ->enabledWhen(['==' => [['var' => 'status'], 'draft']]),
            Field::enum('status', PostStatus::class),
            Field::int('owner_id')->label('Owner'),
        ];
    }
}
