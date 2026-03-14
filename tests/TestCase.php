<?php

namespace Spawnflow\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Spawnflow\SpawnflowServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SpawnflowServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('spawnflow.subjects', [
            'posts' => \Spawnflow\Tests\Fixtures\Post::class,
        ]);

        $app['config']->set('spawnflow.contexts', [
            'posts' => \Spawnflow\Tests\Fixtures\PostContext::class,
        ]);

        $app['config']->set('spawnflow.ownership_column', 'owner_id');
        $app['config']->set('spawnflow.user_key', 'id');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
