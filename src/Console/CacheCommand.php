<?php

namespace Spawnflow\Console;

use Illuminate\Console\Command;
use Spawnflow\Discovery\SubjectDiscovery;

/**
 * Freeze attribute discovery into a bootstrap cache file (deploy-time),
 * mirroring Laravel's own presence-decides caches. spawnflow:clear (or
 * generating a new resource) removes it.
 */
class CacheCommand extends Command
{
    protected $signature = 'spawnflow:cache';

    protected $description = 'Cache attribute-discovered Spawnflow subjects for production';

    public function handle(): int
    {
        $maps = SubjectDiscovery::scan();

        file_put_contents(
            SubjectDiscovery::cachePath(),
            '<?php return '.var_export($maps, true).';',
        );

        $this->components->info('Spawnflow subjects cached ('.count($maps['subjects']).' discovered).');

        return self::SUCCESS;
    }
}
