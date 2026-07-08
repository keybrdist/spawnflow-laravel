<?php

namespace Spawnflow\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;

/**
 * "Why is this field hidden?" — evaluate a subject's eligibility rules
 * against hypothetical form values. Pure delegation to Eligibility;
 * no database, no writes.
 */
class CheckEligibility extends Tool
{
    use ResolvesSubjects;

    protected string $description = 'Evaluate a subject\'s eligibility rules against hypothetical form values. Returns per-field and per-group visible/enabled verdicts plus the list of fields whose values would be discarded on save. Use to debug why a field is hidden or disabled.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Subject alias, e.g. "posts"')->required(),
            'values' => $schema->object()->description('Hypothetical form values, e.g. {"ctype": "event"}')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $alias = $this->knownAlias($request->get('subject', ''));
        if ($alias === null) {
            return $this->unknownSubject((string) $request->get('subject'));
        }

        $fieldSet = $this->registry()->fieldsFor($alias);
        if ($fieldSet === null) {
            return Response::error("Subject '{$alias}' has no FieldSet — no eligibility rules to evaluate.");
        }

        $values = (array) $request->get('values', []);

        return Response::json([
            'fields' => Eligibility::fieldVerdicts($fieldSet, $values),
            'groups' => Eligibility::groupVerdicts($fieldSet, $values),
            'ineligible' => Eligibility::ineligible($fieldSet, $values),
        ]);
    }
}
