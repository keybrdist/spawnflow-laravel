<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Http\OptionsController;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Relation field options — delegates to the OptionsController that backs
 * searchable selects, so ownership scoping (and its fail-closed missing-
 * column guard) stays defined once.
 */
class FieldOptions extends Tool
{
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'Get {value, label} option pages for a relation field (the data source behind searchable selects). Ownership-scoped unless the field is declared unscoped.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'field' => $schema->string()->description('Relation field name')->required(),
            'q' => $schema->string()->description('Search term (searchable fields only)'),
            'page' => $schema->integer()->description('Page number (default 1)'),
            'per_page' => $schema->integer()->description('Options per page (default 20, max 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $http = $this->httpRequest($request);
        $http->query->add(array_filter([
            'q' => $request->get('q'),
            'page' => $request->get('page'),
            'per_page' => $request->get('per_page'),
        ], fn ($value) => $value !== null && $value !== ''));

        $response = (new OptionsController)->show($http, $alias, (string) $request->get('field'));

        if ($response->getStatusCode() >= 400) {
            return Response::error((string) ($response->getData(true)['error'] ?? 'Options lookup failed.'));
        }

        return Response::json($response->getData(true));
    }
}
