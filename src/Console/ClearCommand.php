<?php

namespace Spawnflow\Console;

use Illuminate\Console\Command;
use Spawnflow\Discovery\SubjectDiscovery;

class ClearCommand extends Command
{
    protected $signature = 'spawnflow:clear';

    protected $description = 'Remove the cached Spawnflow subject discovery file';

    public function handle(): int
    {
        $cache = SubjectDiscovery::cachePath();

        if (is_file($cache)) {
            unlink($cache);
            $this->components->info('Spawnflow discovery cache cleared.');
        } else {
            $this->components->info('No discovery cache to clear.');
        }

        return self::SUCCESS;
    }
}
