<?php

namespace Spawnflow\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

/**
 * Canned workflow: bisect why a field is hidden/disabled.
 */
class DebugEligibility extends Prompt
{
    protected string $description = 'Guided workflow for debugging why a field is hidden, disabled, or discarded on save.';

    public function arguments(): array
    {
        return [
            new Argument('subject', 'Subject alias, e.g. "campaigns"', required: true),
            new Argument('field', 'The field in question', required: true),
        ];
    }

    public function handle(Request $request): Response
    {
        $subject = (string) $request->get('subject', 'subject');
        $field = (string) $request->get('field', 'field');

        return Response::text(<<<MARKDOWN
            Debug why "{$field}" on "{$subject}" is hidden/disabled/discarded:

            1. `get-schema` for "{$subject}" — find "{$field}". Two independent
               axes can gate it:
               - `eligibility` rules on the field or its group (data-reactive),
               - the permission variant (role×record-state, in `variants`).
            2. For the rule axis: call `check-eligibility` with the CURRENT form
               values; read the verdict for "{$field}" and its group (a hidden
               group hides its members — AND composition). Bisect by flipping one
               referenced field at a time (rule conditions name their inputs).
            3. For the variant axis: is "{$field}" in the active variant's
               `editable_fields`? If not, the context enum excludes it for this
               role/record-state — that is authoritative and rules cannot
               override it.
            4. Remember write-path enforcement: a rule-ineligible field's value
               is DISCARDED by save (not errored). `validate-payload` shows this:
               the payload passes, the field just won't persist.
            MARKDOWN);
    }
}
