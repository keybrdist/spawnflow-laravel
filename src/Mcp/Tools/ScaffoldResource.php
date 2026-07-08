<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Dev-time scaffolding: delegates to `spawnflow:resource --generate` — the
 * same artisan entry point a human runs, so behavior cannot fork. Returns
 * the generated FieldSet source for agent review (the generated file is the
 * canonical declaration and is meant to be edited).
 *
 * Registered ONLY in the local environment over stdio: absent from
 * tools/list everywhere else — absence beats a runtime guard.
 */
class ScaffoldResource extends Tool
{
    protected string $description = 'Scaffold a new SpawnFlow resource: introspects the real database table (columns, foreign keys, enum columns, legacy on/off flags) and generates the FieldSet declaration. Returns the generated file contents for review. Local development only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Resource name, e.g. "Campaign" (StudlyCase model name)')->required(),
        ];
    }

    public function eligibleForRegistration(): bool
    {
        return app()->environment('local') && app()->runningInConsole();
    }

    public function handle(Request $request): Response
    {
        $name = (string) $request->get('name', '');
        if ($name === '') {
            return Response::error('A resource name is required.');
        }

        $exit = Artisan::call('spawnflow:resource', ['name' => $name, '--generate' => true]);
        $output = trim(Artisan::output());

        if ($exit !== 0) {
            return Response::error("spawnflow:resource failed:\n{$output}");
        }

        // Surface the generated declaration so the agent reviews it — the
        // command output names the written file(s).
        $files = [];
        foreach (glob(app_path('Spawnflow/*.php')) ?: [] as $path) {
            if (str_contains(basename($path), $name)) {
                $files[basename($path)] = file_get_contents($path);
            }
        }

        return Response::json([
            'output' => $output,
            'generated' => $files,
            'note' => 'The generated FieldSet is the canonical declaration — review and edit it; schema drift stays visible in code.',
        ]);
    }
}
