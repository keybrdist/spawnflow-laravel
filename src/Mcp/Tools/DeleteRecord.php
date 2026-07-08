<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\RegistersWhenAuthenticated;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Single-id delete, ownership-verified via ask(). Deliberately no bulk
 * form on MCP v1 — write amplification stays bounded.
 */
class DeleteRecord extends Tool
{
    use RegistersWhenAuthenticated;
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'Delete one record by id (ownership-verified). No bulk delete exists over MCP.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'id' => $schema->integer()->description('Record id')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $id = (int) $request->get('id');

        try {
            (new Flow)
                ->spawn($this->httpRequest($request))
                ->auth()
                ->resolve($alias)
                ->ask('DELETE', $id)
                ->delete($id);
        } catch (OwnershipException) {
            return Response::error("Record not found: {$alias}/{$id}");
        }

        return Response::json(['deleted' => true, 'id' => $id]);
    }
}
