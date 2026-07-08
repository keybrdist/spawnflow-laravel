<?php

namespace Spawnflow\Mcp\Tools\Concerns;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;

/**
 * Bridges an MCP request to the Illuminate Request the Flow chain consumes.
 * The MCP-authenticated user IS the Flow user — there is deliberately no
 * impersonation parameter.
 */
trait RunsFlows
{
    protected function httpRequest(Request $request, array $data = []): HttpRequest
    {
        $http = HttpRequest::create('/mcp', 'POST', $data);
        $http->setUserResolver(fn () => $request->user());

        return $http;
    }
}
