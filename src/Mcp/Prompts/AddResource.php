<?php

namespace Spawnflow\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * Canned workflow: scaffold a subject from a real table, review the
 * generated declaration, wire eligibility, regenerate types.
 */
class AddResource extends Prompt
{
    protected string $description = 'Guided workflow for adding a new SpawnFlow resource from an existing database table.';

    public function arguments(): array
    {
        return [
            new Argument('name', 'Resource name, e.g. "Campaign"', required: true),
        ];
    }

    public function handle(Request $request): Response
    {
        $name = (string) $request->get('name', 'Resource');

        return Response::text(<<<MARKDOWN
            Add the SpawnFlow resource "{$name}" end to end:

            1. Call `scaffold-resource` with name "{$name}" — it introspects the
               real table (columns, FKs, enum columns, legacy on/off flags) and
               generates the FieldSet declaration.
            2. REVIEW the generated file it returns: the declaration is canonical
               and meant to be edited. Check labels, rules, any
               `/* unrecognized column type */` or FK placeholder comments.
            3. If fields should show/hide/enable on other field values, add
               eligibility rules (`visibleWhen`/`hiddenWhen`/`enabledWhen`/
               `disabledWhen` with restricted JSON Logic) or a `Group`. Verify
               with `check-eligibility` against representative form values.
            4. If a role×record-state permission axis is needed, create a
               FieldContext enum (`php artisan spawnflow:make-context`) — rules
               may NOT reference roles; that axis lives in the enum.
            5. Call `generate-types` to refresh the TypeScript/Zod artifacts.
            6. Confirm the contract with `get-schema` for the new alias.
            MARKDOWN);
    }
}
