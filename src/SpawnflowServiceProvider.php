<?php

namespace Spawnflow;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Http\SchemaController;

class SpawnflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/spawnflow.php', 'spawnflow');

        $this->app->singleton(SubjectRegistry::class, ConfigSubjectRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/spawnflow.php' => config_path('spawnflow.php'),
            ], 'spawnflow-config');

            $this->publishes([
                __DIR__.'/../stubs' => base_path('stubs/spawnflow'),
            ], 'spawnflow-stubs');

            $this->commands([
                \Spawnflow\Console\CacheCommand::class,
                \Spawnflow\Console\ClearCommand::class,
                \Spawnflow\Console\GenerateCommand::class,
                \Spawnflow\Console\InstallCommand::class,
                \Spawnflow\Console\MakeContextCommand::class,
                \Spawnflow\Console\ResourceCommand::class,
            ]);
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'spawnflow');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/spawnflow'),
            ], 'spawnflow-views');
        }

        // Optional renderer: registered only when the host app ships
        // Livewire — the package does not depend on it.
        if (class_exists(\Livewire\Livewire::class)) {
            \Livewire\Livewire::component('spawnflow-form', \Spawnflow\Livewire\SpawnForm::class);
        }

        $this->registerSchemaRoutes();
        $this->registerMcpServer();
    }

    /**
     * Optional MCP server: registered only when the host app enables it
     * (and ships laravel/mcp — the package does not hard-depend on it).
     * stdio handle 'spawnflow' when enabled; streamable HTTP additionally
     * behind auth middleware when 'web' is on. Off = no-op.
     */
    protected function registerMcpServer(): void
    {
        if (! config('spawnflow.mcp.enabled', false) || ! class_exists(\Laravel\Mcp\Server::class)) {
            return;
        }

        \Laravel\Mcp\Facades\Mcp::local('spawnflow', \Spawnflow\Mcp\SpawnflowServer::class);

        if (config('spawnflow.mcp.web', false)) {
            \Laravel\Mcp\Facades\Mcp::web(
                config('spawnflow.mcp.web_route', '/mcp/spawnflow'),
                \Spawnflow\Mcp\SpawnflowServer::class,
            )->middleware(config('spawnflow.mcp.web_middleware', ['auth:api', 'throttle:60,1']));
        }
    }

    protected function registerSchemaRoutes(): void
    {
        if (! config('spawnflow.schema_routes', false)) {
            return;
        }

        Route::middleware(config('spawnflow.schema_middleware', ['auth:api']))
            ->prefix('spawnflow')
            ->group(function (): void {
                Route::get('/schema/{subject}/{id?}', [SchemaController::class, 'show'])
                    ->whereNumber('id');
                Route::get('/options/{subject}/{field}', [\Spawnflow\Http\OptionsController::class, 'show']);

                // The SSE channel rides Laravel 12's eventStream/StreamedEvent
                // API — on Laravel 11 the route is simply absent.
                if (config('spawnflow.events', false) && class_exists(\Illuminate\Http\StreamedEvent::class)) {
                    Route::get('/events', [\Spawnflow\Http\EventsController::class, 'show']);
                }
            });
    }
}
