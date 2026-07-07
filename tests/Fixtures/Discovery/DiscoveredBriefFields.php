<?php

namespace Spawnflow\Tests\Fixtures\Discovery;

use Spawnflow\Attributes\SpawnSubject;
use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\PostContext;

#[SpawnSubject('briefs', model: Post::class, context: PostContext::class)]
class DiscoveredBriefFields extends FieldSet
{
    public static function fields(): array
    {
        return [
            Field::string('title')->rules('required|string|max:255'),
        ];
    }
}
