<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Exceptions\ForbiddenFieldAccessException;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Flow;
use Spawnflow\Mcp\Tools\Concerns\RegistersWhenAuthenticated;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;
use Spawnflow\Mcp\Tools\Concerns\RunsFlows;
use Spawnflow\Validation\RuleResolver;

/**
 * Partial update via the full Flow chain. Validates only the submitted
 * fields against their descriptor rules (the GenericController partial-
 * update semantics), then returns the PERSISTED record — the server may
 * have discarded context- or rule-ineligible fields.
 */
class UpdateRecord extends Tool
{
    use RegistersWhenAuthenticated;
    use ResolvesSubjects;
    use RunsFlows;

    protected string $description = 'Update a record by id (partial update: only submitted fields are validated). Ownership-verified; returns the persisted record — trust it over the submitted payload, the chain may discard ineligible fields.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'id' => $schema->integer()->description('Record id')->required(),
            'payload' => $schema->object()->description('Fields to update')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $id = (int) $request->get('id');
        $payload = (array) $request->get('payload', []);

        try {
            $flow = (new Flow)
                ->spawn($this->httpRequest($request, $payload))
                ->auth()
                ->resolve($alias)
                ->ask('POST', $id)
                ->fields();

            // Partial-update semantics: validate only the submitted fields —
            // resolved AGAINST the record's active context (its validation()
            // overrides win), minus rule-ineligible fields, whose values the
            // save path discards rather than validates.
            $rules = array_intersect_key(
                app(RuleResolver::class)->for($alias, $flow->getContext()),
                $payload,
            );

            $fieldSet = $this->registry()->fieldsFor($alias);
            if ($fieldSet !== null) {
                $state = array_merge($flow->getInstance()?->attributesToArray() ?? [], $payload);
                $rules = array_diff_key($rules, array_flip(Eligibility::ineligible($fieldSet, $state)));
            }

            $response = $flow
                ->validate($rules, data: $payload)
                ->save($payload)
                ->present();
        } catch (ValidationException $e) {
            return Response::json(['updated' => false, 'errors' => $e->errors()]);
        } catch (OwnershipException) {
            return Response::error("Record not found: {$alias}/{$id}");
        } catch (ForbiddenFieldAccessException) {
            return Response::error('Your permission context has no editable fields on this record.');
        }

        return Response::json($response->getData(true));
    }
}
