<?php

namespace Spawnflow\Tests\Fixtures;

use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;

class ArticleFields extends FieldSet
{
    public static function fields(): array
    {
        return [
            Field::string('title')->rules('required|string|max:100'),
            Field::belongsTo('post_id', Post::class)->display('title')->searchable(),
        ];
    }
}
