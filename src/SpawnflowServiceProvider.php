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

                if (config('spawnflow.events', false)) {
                    Route::get('/events', [\Spawnflow\Http\EventsController::class, 'show']);
                }
            });
    }
}
