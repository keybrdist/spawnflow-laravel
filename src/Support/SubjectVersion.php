<?php

namespace Spawnflow\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Per-subject change counters behind the SSE invalidation channel.
 *
 * Writers bump; the events endpoint diffs snapshots and pushes "this
 * subject changed" signals. Deliberately NOT state sync: clients react
 * by refetching through the same HTTP contract they already use, so a
 * dropped connection degrades to non-live — never to wrong data.
 */
class SubjectVersion
{
    protected const PREFIX = 'spawnflow:version:';

    public static function bump(string $alias): void
    {
        $store = Cache::store(config('spawnflow.events_cache_store'));

        // add() initializes without clobbering a concurrent increment.
        $store->add(self::PREFIX.$alias, 0);
        $store->increment(self::PREFIX.$alias);
    }

    /**
     * @param  list<string>  $aliases
     * @return array<string, int>
     */
    public static function snapshot(array $aliases): array
    {
        $store = Cache::store(config('spawnflow.events_cache_store'));

        $versions = [];
        foreach ($aliases as $alias) {
            $versions[$alias] = (int) $store->get(self::PREFIX.$alias, 0);
        }

        return $versions;
    }
}
