<?php

namespace Spawnflow\Attributes;

use Attribute;

/**
 * Marks a FieldSet as a self-registering Spawnflow subject.
 *
 * The discovery scanner (see Discovery\SubjectDiscovery) picks these up
 * from the configured discovery path, so a generated FieldSet registers
 * its alias, model, and context without touching config — config entries
 * still override attributes on conflict.
 *
 *   #[SpawnSubject('posts', model: Post::class, context: PostContext::class)]
 *   class PostFields extends FieldSet { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SpawnSubject
{
    /**
     * @param  string  $alias  URL segment / registry alias.
     * @param  class-string  $model  The subject's Eloquent model.
     * @param  class-string|null  $context  Optional FieldContext enum.
     */
    public function __construct(
        public readonly string $alias,
        public readonly string $model,
        public readonly ?string $context = null,
    ) {}
}
