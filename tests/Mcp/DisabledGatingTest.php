<?php

use Laravel\Mcp\Facades\Mcp;

// Base TestCase leaves spawnflow.mcp at its defaults (disabled) — the
// production-shaped case: registration must be a complete no-op.

test('a disabled mcp config registers nothing', function (): void {
    expect(config('spawnflow.mcp.enabled'))->toBeFalse()
        ->and(Mcp::getLocalServer('spawnflow'))->toBeNull()
        ->and(Mcp::getWebServer('mcp/spawnflow'))->toBeNull();
});
