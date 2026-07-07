<?php

namespace Spawnflow\Discovery;

use ReflectionClass;
use Spawnflow\Attributes\SpawnSubject;
use Spawnflow\Schema\FieldSet;

/**
 * Scans the discovery path for FieldSets carrying #[SpawnSubject] and
 * turns them into registry maps. Dev scans per boot (the path is small);
 * production reads the cache file written by `spawnflow:cache` — the
 * same presence-decides model as Laravel's own bootstrap caches.
 */
class SubjectDiscovery
{
    /**
     * @return array{subjects: array<string, class-string>, contexts: array<string, class-string>, fields: array<string, class-string>}
     */
    public static function discover(): array
    {
        $cache = static::cachePath();
        if (is_file($cache)) {
            return require $cache;
        }

        return static::scan();
    }

    /**
     * @return array{subjects: array<string, class-string>, contexts: array<string, class-string>, fields: array<string, class-string>}
     */
    public static function scan(): array
    {
        $maps = ['subjects' => [], 'contexts' => [], 'fields' => []];

        $path = config('spawnflow.discovery_path') ?? app_path('Spawnflow');
        if (! is_dir($path)) {
            return $maps;
        }

        foreach (static::classesIn($path) as $class) {
            if (! is_subclass_of($class, FieldSet::class)) {
                continue;
            }

            $attributes = (new ReflectionClass($class))->getAttributes(SpawnSubject::class);
            if ($attributes === []) {
                continue;
            }

            $subject = $attributes[0]->newInstance();
            $alias = mb_strtolower($subject->alias);

            $maps['subjects'][$alias] = $subject->model;
            $maps['fields'][$alias] = $class;
            if ($subject->context !== null) {
                $maps['contexts'][$alias] = $subject->context;
            }
        }

        return $maps;
    }

    public static function cachePath(): string
    {
        return app()->bootstrapPath('cache/spawnflow.php');
    }

    /**
     * FQCNs of PHP classes under the path, parsed from each file's
     * namespace and class declarations — no path/namespace convention
     * required, so any discovery_path works.
     *
     * @return list<class-string>
     */
    protected static function classesIn(string $path): array
    {
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! preg_match('/^namespace\s+([^;]+);/m', $contents, $ns) ||
                ! preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $contents, $cls)) {
                continue;
            }

            $class = trim($ns[1]).'\\'.$cls[1];

            if (! class_exists($class)) {
                // Generated files may not be composer-autoloadable yet
                // (fresh scaffold before dump-autoload) — load directly.
                require_once $file->getPathname();
            }

            if (class_exists($class, false) || class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
