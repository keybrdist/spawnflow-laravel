<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;

/**
 * Dry-run validation: the explicit-data path of Flow::validate(), never
 * a write. Rule-ineligible fields skip validation (their values would be
 * discarded by save), and wire formats coerce first — the exact semantics
 * a subsequent create/update will apply.
 */
class ValidatePayload extends Tool
{
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'Dry-run a payload against a subject\'s validation rules without writing anything. Returns valid=true or per-field errors. Applies the same discard/coercion semantics the save path uses, so a passing payload will save.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'payload' => $schema->object()->description('The payload to validate')->required(),
            'record_id' => $schema->integer()->description('Optional record id — validates against that record\'s state (requires authentication and ownership)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $payload = (array) $request->get('payload', []);
        $recordId = $request->get('record_id');

        $flow = (new Flow)
            ->spawn($this->httpRequest($request))
            ->resolve($alias);

        try {
            if ($recordId !== null) {
                if ($request->user() === null) {
                    return Response::error('Authentication required to validate against a specific record.');
                }
                // auth() only records the user; ask() is the ownership gate.
                $flow->auth()->ask('POST', (int) $recordId);
            }

            $flow->validate(data: $payload);
        } catch (ValidationException $e) {
            return Response::json(['valid' => false, 'errors' => $e->errors()]);
        } catch (OwnershipException) {
            return Response::error("Record not found: {$alias}/{$recordId}");
        }

        return Response::json(['valid' => true]);
    }
}
