<?php

namespace Spawnflow;

use Illuminate\Database\Eloquent\Model;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Discovery\SubjectDiscovery;
use Spawnflow\Exceptions\UnresolvableSubjectException;

class ConfigSubjectRegistry implements SubjectRegistry
{
    /** @var array<string, class-string<Model>> */
    protected array $subjects;

    /** @var array<string, class-string|null> */
    protected array $contexts;

    /** @var array<string, class-string|null> */
    protected array $fields;

    public function __construct()
    {
        // Attribute-discovered subjects register themselves; config
        // entries override them on conflict.
        $discovered = config('spawnflow.discovery', true)
            ? SubjectDiscovery::discover()
            : ['subjects' => [], 'contexts' => [], 'fields' => []];

        $this->subjects = array_merge($discovered['subjects'], config('spawnflow.subjects', []));
        $this->contexts = array_merge($discovered['contexts'], config('spawnflow.contexts', []));
        $this->fields = array_merge($discovered['fields'], config('spawnflow.fields', []));
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

    public function fieldsFor(string $alias): ?string
    {
        $alias = mb_strtolower($alias);

        return $this->fields[$alias] ?? null;
    }

    public function aliasFor(?string $modelClass): ?string
    {
        if ($modelClass === null) {
            return null;
        }

        $alias = array_search($modelClass, $this->subjects, true);

        return $alias === false ? null : $alias;
    }

    public function all(): array
    {
        return $this->subjects;
    }
}
