<?php

namespace Spawnflow\Console;

use Illuminate\Console\Command;

/**
 * One-time setup: publish config, create the discovery directory, and
 * point at the next command. Everything else is per-resource
 * (spawnflow:resource).
 */
class InstallCommand extends Command
{
    protected $signature = 'spawnflow:install';

    protected $description = 'Install Spawnflow: publish config and prepare attribute discovery';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'spawnflow-config']);

        $directory = config('spawnflow.discovery_path') ?? app_path('Spawnflow');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
            $this->components->info("Created {$directory} (attribute discovery path).");
        }

        $this->newLine();
        $this->components->info('Spawnflow installed.');
        $this->line('  Next: php artisan spawnflow:resource Post --generate');
        $this->line('  Then: set spawnflow.schema_routes = true to serve the contract, and');
        $this->line('        php artisan spawnflow:generate for TypeScript + Zod clients.');

        return self::SUCCESS;
    }
}
