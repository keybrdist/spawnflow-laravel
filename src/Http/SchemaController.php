<?php

namespace Spawnflow\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Flow;
use Spawnflow\Schema\SchemaSerializer;

/**
 * Serves field schemas (contract v1) for the authenticated user.
 *
 * GET /spawnflow/schema/{subject}        → descriptors + all context variants
 * GET /spawnflow/schema/{subject}/{id}   → resolved variant for a specific record
 *
 * See docs/schema-contract.md for the response contract.
 */
class SchemaController extends Controller
{
    public function show(Request $request, string $subject, ?int $id = null): JsonResponse
    {
        $registry = app(SubjectRegistry::class);

        $alias = mb_strtolower($subject);
        if (! array_key_exists($alias, $registry->all())) {
            return response()->json(['error' => "Unknown subject: {$subject}"], 404);
        }

        $serializer = new SchemaSerializer($registry);
        $contextClass = $registry->contextFor($alias);

        if ($contextClass === null) {
            return response()->json($serializer->defaultSchema($alias));
        }

        // With a record ID, resolve the caller's specific context variant.
        if ($id !== null) {
            $flow = (new Flow)
                ->spawn($request)->auth()
                ->resolve($alias)
                ->ask('GET', $id)
                ->fields($contextClass);

            return response()->json($serializer->resolved($alias, $flow->getContext()));
        }

        return response()->json($serializer->variants($alias, $contextClass));
    }
}
