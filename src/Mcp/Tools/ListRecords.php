<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Ownership-scoped listing via Flow::list() — the authenticated user sees
 * their rows, paginated, nothing else.
 */
class ListRecords extends Tool
{
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'List the authenticated user\'s records for a subject (ownership-scoped, paginated).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'page' => $schema->integer()->description('Page number (default 1)'),
            'per_page' => $schema->integer()->description('Rows per page (default 15, max 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $perPage = min(max((int) $request->get('per_page', 15), 1), 100);

        $response = (new Flow)
            ->spawn($this->httpRequest($request, ['page' => (int) $request->get('page', 1)]))
            ->auth()
            ->resolve($alias)
            ->list($perPage);

        return Response::json($response->getData(true));
    }
}
