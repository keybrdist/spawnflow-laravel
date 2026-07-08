<?php

use Spawnflow\Mcp\SpawnflowServer;
use Spawnflow\Mcp\Tools\ValidatePayload;

// Dry-run semantics must match the write path: rule-ineligible fields skip
// validation (their values would be discarded), wire coercion applies first.

test('a valid payload validates clean without writing', function (): void {
    SpawnflowServer::tool(ValidatePayload::class, [
        'subject' => 'posts',
        'payload' => ['title' => 'Hello', 'status' => 'draft'],
    ])
        ->assertOk()
        ->assertSee('"valid":true');

    expect(\Spawnflow\Tests\Fixtures\Post::count())->toBe(0);
});

test('a missing required field returns per-field errors', function (): void {
    SpawnflowServer::tool(ValidatePayload::class, [
        'subject' => 'posts',
        'payload' => ['status' => 'draft'],
    ])
        ->assertOk()
        ->assertSee('"valid":false')
        ->assertSee('"title"');
});

test('rule-ineligible fields pass validation — discard semantics', function (): void {
    // body is enabledWhen status == draft; for a published payload its rules
    // are skipped because save() would discard the value, not error on it.
    SpawnflowServer::tool(ValidatePayload::class, [
        'subject' => 'posts',
        'payload' => ['title' => 'T', 'status' => 'published', 'body' => str_repeat('x', 10)],
    ])
        ->assertOk()
        ->assertSee('"valid":true');
});

test('unknown subject reads back with the known list', function (): void {
    SpawnflowServer::tool(ValidatePayload::class, ['subject' => 'ghosts', 'payload' => []])
        ->assertHasErrors()
        ->assertSee("Unknown subject 'ghosts'");
});
