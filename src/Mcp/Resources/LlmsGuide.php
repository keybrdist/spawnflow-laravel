<?php

namespace Spawnflow\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * The package's LLM onboarding doc, served verbatim.
 */
class LlmsGuide extends Resource
{
    protected string $name = 'llms';

    protected string $uri = 'spawnflow://llms';

    protected string $mimeType = 'text/plain';

    protected string $description = 'SpawnFlow LLM onboarding: the Flow chain, the two permission axes, the no-drift contract, write-path enforcement.';

    public function handle(): Response
    {
        return Response::text((string) file_get_contents(__DIR__.'/../../../llms.txt'));
    }
}
