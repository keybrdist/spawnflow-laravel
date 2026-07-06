<?php

namespace Spawnflow\Console;

use Illuminate\Console\Command;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Generator\TypeScriptGenerator;
use Spawnflow\Schema\SchemaSerializer;

class GenerateCommand extends Command
{
    protected $signature = 'spawnflow:generate {--path= : Override the configured output path}';

    protected $description = 'Generate TypeScript types and Zod schemas from the Spawnflow schema contract';

    public function handle(SubjectRegistry $registry): int
    {
        $config = config('spawnflow.generator', []);
        $path = rtrim($this->option('path') ?: ($config['output_path'] ?? ''), '/');

        if ($path === '') {
            $this->error('No output path. Set spawnflow.generator.output_path or pass --path.');

            return self::FAILURE;
        }

        $subjects = $registry->all();

        if ($subjects === []) {
            $this->warn('No subjects registered — nothing to generate.');

            return self::SUCCESS;
        }

        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            $this->error("Cannot create output directory: {$path}");

            return self::FAILURE;
        }

        $serializer = new SchemaSerializer($registry);
        $generator = new TypeScriptGenerator($config);

        foreach (array_keys($subjects) as $alias) {
            $contextClass = $registry->contextFor($alias);

            $schema = $contextClass !== null
                ? $serializer->variants($alias, $contextClass)
                : $serializer->defaultSchema($alias);

            file_put_contents("{$path}/{$alias}.ts", $generator->subjectFile($schema));
            $this->components->info("Generated {$alias}.ts");
        }

        if ($config['emit_client'] ?? false) {
            file_put_contents("{$path}/client.ts", $generator->clientFile());
            $this->components->info('Generated client.ts');
        }

        file_put_contents("{$path}/index.ts", $generator->indexFile(array_keys($subjects)));
        $this->components->info('Generated index.ts');

        return self::SUCCESS;
    }
}
