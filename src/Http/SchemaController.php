<?php

namespace Spawnflow\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Flow;

/**
 * Serves field permission schemas for the authenticated user.
 *
 * GET /spawnflow/schema/{subject}        → all variants for the subject
 * GET /spawnflow/schema/{subject}/{id}   → resolved variant for a specific record
 */
class SchemaController extends Controller
{
    public function show(Request $request, string $subject, ?int $id = null): JsonResponse
    {
        $registry = app(SubjectRegistry::class);

        // Validate subject exists in registry before proceeding
        $alias = mb_strtolower($subject);
        if (! array_key_exists($alias, $registry->all())) {
            return response()->json(['error' => "Unknown subject: {$subject}"], 404);
        }

        $contextClass = $registry->contextFor($subject);

        if ($contextClass === null) {
            return response()->json([
                'resource' => $subject,
                'context' => 'default',
                'message' => 'No field context defined — all fillable fields are writable by owner.',
            ]);
        }

        // If a record ID is provided, resolve the specific context
        if ($id !== null) {
            $flow = (new Flow)
                ->spawn($request)->auth()
                ->resolve($subject)
                ->ask('GET', $id)
                ->fields($contextClass);

            $context = $flow->getContext();

            return response()->json([
                'resource' => $subject,
                'context' => $context->value ?? $context->name ?? get_class($context),
                'fields' => $this->buildFieldSchema($context),
            ]);
        }

        // Without an ID, return all variants
        $variants = [];
        foreach ($contextClass::cases() as $case) {
            $variants[] = [
                'context' => $case->value ?? $case->name,
                'editable_fields' => $case->editableFields(),
                'validation' => $case->validation(),
                'visible_fields' => $case->visibleFields(),
            ];
        }

        return response()->json([
            'resource' => $subject,
            'variants' => $variants,
        ]);
    }

    private function buildFieldSchema(mixed $context): array
    {
        $editable = array_flip($context->editableFields());
        $visible = $context->visibleFields();
        $rules = $context->validation();

        $fields = [];
        foreach ($visible as $field) {
            $fields[$field] = [
                'editable' => isset($editable[$field]),
                'rules' => $rules[$field] ?? null,
            ];
        }

        return $fields;
    }
}
