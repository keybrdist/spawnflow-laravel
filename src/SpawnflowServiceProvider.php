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
        }

        $this->registerSchemaRoutes();
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
            });
    }
}
