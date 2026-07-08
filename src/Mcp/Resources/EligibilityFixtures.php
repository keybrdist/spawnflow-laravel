<?php

namespace Spawnflow\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * The cross-runtime conformance cases (shared by the PHP and JS
 * evaluators). Lets an agent verify a third-runtime rule evaluator
 * against the exact same fixtures.
 */
class EligibilityFixtures extends Resource
{
    protected string $name = 'eligibility-fixtures';

    protected string $uri = 'spawnflow://conformance/eligibility-fixtures';

    protected string $mimeType = 'application/json';

    protected string $description = 'Eligibility conformance fixtures: the JSON Logic evaluation cases both the PHP (Pest) and JS (vitest) evaluators are pinned by.';

    public function handle(): Response
    {
        return Response::text((string) file_get_contents(__DIR__.'/../../../resources/conformance/eligibility-fixtures.json'));
    }
}
