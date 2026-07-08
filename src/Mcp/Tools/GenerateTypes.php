<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Dev-time type generation: delegates to `spawnflow:generate`. Registered
 * ONLY in the local environment over stdio (see ScaffoldResource).
 */
class GenerateTypes extends Tool
{
    protected string $description = 'Regenerate the TypeScript types and Zod schemas from the SpawnFlow contract (spawnflow:generate). Run after changing any FieldSet. Local development only.';

    public function eligibleForRegistration(): bool
    {
        return app()->environment('local') && app()->runningInConsole();
    }

    public function handle(): Response
    {
        $exit = Artisan::call('spawnflow:generate');
        $output = trim(Artisan::output());

        if ($exit !== 0) {
            return Response::error("spawnflow:generate failed:\n{$output}");
        }

        return Response::json(['output' => $output]);
    }
}
