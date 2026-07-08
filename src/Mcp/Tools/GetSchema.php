<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\ContextResolver;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Schema\SchemaSerializer;

/**
 * The schema contract, verbatim: tool output IS SchemaSerializer output —
 * the same no-drift payload the schema endpoint serves.
 *
 * Without record_id: descriptors + all context variants (or the default
 * schema for context-less subjects). With record_id: the caller's resolved
 * variant for that record — a user the context enum DENIES gets a response
 * indistinguishable from a missing record, so this is not a cross-tenant
 * existence oracle (same contract as SchemaController).
 */
class GetSchema extends Tool
{
    use ResolvesSubjects;

    protected string $description = 'Get the SpawnFlow schema contract for a subject: field descriptors (type, widget, rules, wire format, eligibility rules) plus permission variants. Pass record_id to resolve the variant for a specific record as the authenticated user.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'record_id' => $schema->integer()->description('Optional record id — resolves the record-state variant for the authenticated user'),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $registry = $this->registry();
        $serializer = new SchemaSerializer($registry);
        $contextClass = $registry->contextFor($alias);
        $recordId = $request->get('record_id');

        if ($contextClass === null) {
            return Response::json($serializer->defaultSchema($alias));
        }

        if ($recordId !== null) {
            $user = $request->user();
            if ($user === null) {
                return Response::error('Authentication required to resolve a record-state variant.');
            }

            $record = $registry->resolve($alias)->newQuery()->find($recordId);
            if ($record === null) {
                return Response::error("Record not found: {$alias}/{$recordId}");
            }

            try {
                $context = app(ContextResolver::class)->resolve($alias, $user, $record, contextClass: $contextClass);
            } catch (OwnershipException) {
                // Denied reads back exactly like missing — no existence oracle.
                return Response::error("Record not found: {$alias}/{$recordId}");
            }

            return Response::json($serializer->resolved($alias, $context, $record->attributesToArray()));
        }

        return Response::json($serializer->variants($alias, $contextClass));
    }
}
