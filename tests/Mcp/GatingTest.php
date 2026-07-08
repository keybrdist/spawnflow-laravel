<?php

use Laravel\Mcp\Facades\Mcp;
use Spawnflow\Mcp\SpawnflowServer;
use Spawnflow\Mcp\Tools\GenerateTypes;
use Spawnflow\Mcp\Tools\ListSubjects;
use Spawnflow\Mcp\Tools\ScaffoldResource;
use Spawnflow\SpawnflowServiceProvider;

// Re-run just the provider's MCP registration after flipping config —
// the package boot already ran with the disabled defaults.
function registerMcp(): void
{
    $provider = new SpawnflowServiceProvider(app());
    (fn () => $this->registerMcpServer())->call($provider);
}

// ---------------------------------------------------------------
// Registration gates
// ---------------------------------------------------------------

test('the server registers stdio and web handles when enabled', function (): void {
    config()->set('spawnflow.mcp.enabled', true);
    config()->set('spawnflow.mcp.web', true);
    config()->set('spawnflow.mcp.web_middleware', []);
    registerMcp();

    expect(Mcp::getLocalServer('spawnflow'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/spawnflow'))->not->toBeNull();
});

test('enabled without web exposes stdio only', function (): void {
    config()->set('spawnflow.mcp.enabled', true);
    config()->set('spawnflow.mcp.web', false);
    registerMcp();

    expect(Mcp::getLocalServer('spawnflow'))->not->toBeNull()
        ->and(Mcp::getWebServer('mcp/spawnflow'))->toBeNull();
});

// ---------------------------------------------------------------
// Dev-tool environment gate: absence from tools/list, not a guard
// ---------------------------------------------------------------

test('dev tools are ineligible outside the local environment', function (): void {
    // Testbench runs env=testing — the production-shaped case.
    expect((new ScaffoldResource)->eligibleForRegistration())->toBeFalse()
        ->and((new GenerateTypes)->eligibleForRegistration())->toBeFalse();
});

test('dev tools become eligible in the local environment over stdio', function (): void {
    $this->app['env'] = 'local';

    expect((new ScaffoldResource)->eligibleForRegistration())->toBeTrue()
        ->and((new GenerateTypes)->eligibleForRegistration())->toBeTrue();
});

test('read tools carry no environment gate', function (): void {
    expect((new ListSubjects)->eligibleForRegistration())->toBeTrue();
});

// ---------------------------------------------------------------
// list-subjects hides model classes outside local
// ---------------------------------------------------------------

test('model class names ship only in the local environment', function (): void {
    SpawnflowServer::tool(ListSubjects::class)
        ->assertOk()
        ->assertDontSee('"model"');

    $this->app['env'] = 'local';

    SpawnflowServer::tool(ListSubjects::class)
        ->assertOk()
        ->assertSee('"model"');
});
