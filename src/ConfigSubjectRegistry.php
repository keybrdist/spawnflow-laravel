<?php

namespace Spawnflow;

use Illuminate\Database\Eloquent\Model;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Exceptions\UnresolvableSubjectException;

class ConfigSubjectRegistry implements SubjectRegistry
{
    /** @var array<string, class-string<Model>> */
    protected array $subjects;

    /** @var array<string, class-string|null> */
    protected array $contexts;

    public function __construct()
    {
        $this->subjects = config('spawnflow.subjects', []);
        $this->contexts = config('spawnflow.contexts', []);
    }

    public function resolve(string $alias): Model
    {
        $alias = mb_strtolower($alias);

        $class = $this->subjects[$alias] ?? throw new UnresolvableSubjectException($alias);

        return new $class;
    }

    public function contextFor(string $alias): ?string
    {
        $alias = mb_strtolower($alias);

        return $this->contexts[$alias] ?? null;
    }

    public function all(): array
    {
        return $this->subjects;
    }
}
