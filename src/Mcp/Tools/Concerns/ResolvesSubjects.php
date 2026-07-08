<?php

namespace Spawnflow\Mcp\Tools\Concerns;

use Laravel\Mcp\Response;
use Spawnflow\Contracts\SubjectRegistry;

/**
 * Shared alias lookup for MCP tools: one place decides how an unknown
 * subject reads back to the agent.
 */
trait ResolvesSubjects
{
    protected function registry(): SubjectRegistry
    {
        return app(SubjectRegistry::class);
    }

    protected function knownAlias(string $subject): ?string
    {
        $alias = mb_strtolower($subject);

        return array_key_exists($alias, $this->registry()->all()) ? $alias : null;
    }

    protected function unknownSubject(string $subject): Response
    {
        $known = implode(', ', array_keys($this->registry()->all()));

        return Response::error("Unknown subject '{$subject}'. Known subjects: {$known}.");
    }
}
