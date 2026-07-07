<?php

namespace Spawnflow\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Spawnflow\SpawnflowServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            SpawnflowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('spawnflow.subjects', [
            'posts' => \Spawnflow\Tests\Fixtures\Post::class,
            // Same model under a context-less alias, for fieldset-only paths.
            'articles' => \Spawnflow\Tests\Fixtures\Post::class,
        ]);

        $app['config']->set('spawnflow.contexts', [
            'posts' => \Spawnflow\Tests\Fixtures\PostContext::class,
        ]);

        $app['config']->set('spawnflow.fields', [
            'posts' => \Spawnflow\Tests\Fixtures\PostFields::class,
            'articles' => \Spawnflow\Tests\Fixtures\ArticleFields::class,
        ]);

        $app['config']->set('spawnflow.schema_routes', true);
        $app['config']->set('spawnflow.schema_middleware', []);

        $app['config']->set('spawnflow.ownership_column', 'owner_id');
        $app['config']->set('spawnflow.user_key', 'id');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
