<?php

namespace Spawnflow\Events;

/**
 * A subject's data changed through the Flow write path. The SSE channel
 * consumes the version bump; this typed event is the hook for everything
 * else (Reverb broadcasting, cache busting, audit).
 */
class SubjectChanged
{
    public function __construct(
        public readonly string $subject,
        public readonly int|string|null $id = null,
        public readonly string $action = 'saved',
    ) {}
}
