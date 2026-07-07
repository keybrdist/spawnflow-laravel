<?php

namespace Spawnflow\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

class MakeContextCommand extends GeneratorCommand
{
    protected $name = 'make:spawnflow-context';

    protected $description = 'Create a new Spawnflow FieldContext enum';

    protected $type = 'Context';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/spawnflow/context-enum.stub');

        return file_exists($published)
            ? $published
            : __DIR__.'/../../stubs/context-enum.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Spawnflow';
    }

    protected function getOptions(): array
    {
        return [
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the context if it already exists'],
        ];
    }
}
