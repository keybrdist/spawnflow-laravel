<?php

namespace Spawnflow\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * The schema contract v1 reference, served verbatim.
 */
class SchemaContractDoc extends Resource
{
    protected string $name = 'schema-contract';

    protected string $uri = 'spawnflow://docs/schema-contract';

    protected string $mimeType = 'text/markdown';

    protected string $description = 'SpawnFlow schema contract v1 reference: response shapes for descriptors, variants, groups, eligibility, resolved verdicts.';

    public function handle(): Response
    {
        return Response::text((string) file_get_contents(__DIR__.'/../../../docs/schema-contract.md'));
    }
}
