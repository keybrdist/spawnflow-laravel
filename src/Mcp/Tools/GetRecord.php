<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Exceptions\ForbiddenFieldAccessException;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\RegistersWhenAuthenticated;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Single record via ask()→present(): ownership-verified, response filtered
 * by the resolved context's visibleFields().
 */
class GetRecord extends Tool
{
    use RegistersWhenAuthenticated;
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'Get one record by id (ownership-verified; fields filtered by the caller\'s permission context).';

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
            $response = (new Flow)
                ->spawn($this->httpRequest($request))
                ->auth()
                ->resolve($alias)
                ->ask('GET', $id)
                ->fields()
                ->present();
        } catch (OwnershipException) {
            return Response::error("Record not found: {$alias}/{$id}");
        } catch (ForbiddenFieldAccessException) {
            // Fail closed: a context with zero editable fields also reads
            // nothing through this tool rather than leaking unfiltered data.
            return Response::error("Record not found: {$alias}/{$id}");
        }

        return Response::json($response->getData(true));
    }
}
