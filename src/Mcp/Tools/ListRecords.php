<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Pagination\Paginator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\RegistersWhenAuthenticated;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Ownership-scoped listing via Flow::list() — the authenticated user sees
 * their rows, paginated, nothing else.
 */
class ListRecords extends Tool
{
    use RegistersWhenAuthenticated;
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

        // paginate() reads the page through the global resolver, not the
        // synthetic request — pin it explicitly (same as GenericController).
        $page = max((int) $request->get('page', 1), 1);
        Paginator::currentPageResolver(fn (): int => $page);

        $response = (new Flow)
            ->spawn($this->httpRequest($request))
            ->auth()
            ->resolve($alias)
            ->list($perPage);

        return Response::json($response->getData(true));
    }
}
