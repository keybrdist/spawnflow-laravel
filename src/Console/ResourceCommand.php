<?php

namespace Spawnflow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spawnflow\Discovery\SubjectDiscovery;
use Spawnflow\Generator\Scaffolder;
use Spawnflow\Generator\TableIntrospector;

/**
 * The 5-minute path: one command from an existing table to a registered,
 * permission-aware resource.
 *
 *   php artisan spawnflow:resource Post --generate
 *
 * Writes app/Spawnflow/{Name}Fields.php (self-registering via
 * #[SpawnSubject]) and {Name}Context.php. --generate infers field
 * descriptors from the table's real columns and foreign keys; without
 * it, both files scaffold with commented examples. Inference is
 * make-time only — the generated file is the canonical declaration.
 */
class ResourceCommand extends Command
{
    protected $signature = 'spawnflow:resource
        {name : Resource name in StudlyCase, e.g. Post}
        {--generate : Infer field descriptors from the database table}
        {--model= : Model class (default: App\\Models\\{name})}
        {--table= : Table name (default: the model\'s table)}
        {--force : Overwrite existing files}';

    protected $description = 'Scaffold a Spawnflow resource: FieldSet + FieldContext, self-registered via #[SpawnSubject]';

    public function handle(): int
    {
        $name = Str::studly($this->argument('name'));
        $alias = Str::plural(Str::snake($name));
        $model = $this->option('model') ?: 'App\\Models\\'.$name;

        $namespace = rtrim(app()->getNamespace(), '\\').'\\Spawnflow';
        $directory = app_path('Spawnflow');
        $fieldsClass = "{$name}Fields";
        $contextClass = "{$name}Context";

        $fieldsPath = "{$directory}/{$fieldsClass}.php";
        $contextPath = "{$directory}/{$contextClass}.php";

        foreach ([$fieldsPath, $contextPath] as $path) {
            if (is_file($path) && ! $this->option('force')) {
                $this->components->error(basename($path).' already exists. Use --force to overwrite.');

                return self::FAILURE;
            }
        }

        [$fieldLines, $names, $visible] = $this->fieldPlan($model);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fieldsPath, Scaffolder::fieldSet(
            $namespace,
            $fieldsClass,
            $alias,
            $model,
            $namespace.'\\'.$contextClass,
            $fieldLines,
        ));
        $this->components->info("Created {$fieldsPath}");

        file_put_contents($contextPath, Scaffolder::contextEnum($namespace, $contextClass, $this->contextLists($names, $visible)));
        $this->components->info("Created {$contextPath}");

        // The discovery cache (if built) predates this resource — bust it
        // so the new subject is immediately routable.
        if (is_file($cache = SubjectDiscovery::cachePath())) {
            unlink($cache);
            $this->components->info('Discovery cache cleared.');
        }

        $this->line("  Registered as subject '{$alias}' via #[SpawnSubject] — no config edit needed.");
        $this->line("  Try: GET /spawnflow/schema/{$alias}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    protected function fieldPlan(string $model): array
    {
        if (! $this->option('generate')) {
            return [
                "            // Field::string('title')->rules('required|string|max:255'),\n".
                "            // Field::text('description')->nullable(),",
                [],
                ['id'],
            ];
        }

        $table = $this->option('table')
            ?: (class_exists($model) ? (new $model)->getTable() : Str::plural(Str::snake(class_basename($model))));

        if (! Schema::hasTable($table)) {
            $this->components->warn("Table '{$table}' not found — scaffolding without inference.");

            return $this->withoutGenerate();
        }

        $plan = (new TableIntrospector)->introspect($table);

        return [$plan['lines'], $plan['names'], $plan['visible']];
    }

    /**
     * @return array{0: string, 1: list<string>, 2: list<string>}
     */
    protected function withoutGenerate(): array
    {
        return [
            "            // Field::string('title')->rules('required|string|max:255'),",
            [],
            ['id'],
        ];
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $visible
     * @return array{ownerEditable: string, ownerValidation: string, ownerVisible: string, viewerVisible: string}
     */
    protected function contextLists(array $names, array $visible): array
    {
        if ($names === []) {
            return Scaffolder::defaultContextLists();
        }

        return [
            'ownerEditable' => Scaffolder::lines($names, 16),
            // Rules live on the Field descriptors (one source); per-case
            // overrides go here when a variant needs different rules.
            'ownerValidation' => '                //',
            'ownerVisible' => Scaffolder::lines($visible, 16),
            'viewerVisible' => Scaffolder::lines($visible, 16),
        ];
    }
}
