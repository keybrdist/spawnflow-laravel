<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Exceptions\ForbiddenFieldAccessException;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\RegistersWhenAuthenticated;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Create via the full Flow chain: context-ineligible fields stripped,
 * rule-ineligible values discarded, wire formats coerced, ownership stamped.
 * Returns the PERSISTED record — trust it over the submitted payload.
 */
class CreateRecord extends Tool
{
    use RegistersWhenAuthenticated;
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'Create a record. Runs the full SpawnFlow chain (validation, permission stripping, eligibility discard, wire coercion) and returns the persisted record — fields the chain discarded will be absent or unchanged in the response.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'payload' => $schema->object()->description('Field values for the new record')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $payload = (array) $request->get('payload', []);

        try {
            $response = (new Flow)
                ->spawn($this->httpRequest($request, $payload))
                ->auth()
                ->resolve($alias)
                ->fields()
                ->validate(data: $payload)
                ->save($payload)
                ->present(statusCode: 201);
        } catch (ValidationException $e) {
            return Response::json(['created' => false, 'errors' => $e->errors()]);
        } catch (ForbiddenFieldAccessException) {
            return Response::error('Your permission context has no editable fields on this subject.');
        }

        return Response::json($response->getData(true));
    }
}
