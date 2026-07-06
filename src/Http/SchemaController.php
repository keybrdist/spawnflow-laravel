<?php

namespace Spawnflow\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Spawnflow\ContextResolver;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Exceptions\UnauthenticatedException;
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
        // Resolution is read-only: any authenticated user gets their variant
        // (owners resolve owner cases, everyone else e.g. a viewer case) —
        // the context enum is the authorization decision.
        if ($id !== null) {
            $user = $request->user();
            if ($user === null) {
                throw new UnauthenticatedException;
            }

            $record = $registry->resolve($alias)->newQuery()->find($id);
            if ($record === null) {
                return response()->json(['error' => "Record not found: {$alias}/{$id}"], 404);
            }

            // The context enum is the authorization decision: a user the enum
            // DENIES (resolve() returned null -> OwnershipException) must get
            // a response indistinguishable from a missing record, so the
            // endpoint is not a cross-tenant existence oracle.
            try {
                $context = app(ContextResolver::class)->resolve($alias, $user, $record, contextClass: $contextClass);
            } catch (OwnershipException) {
                return response()->json(['error' => "Record not found: {$alias}/{$id}"], 404);
            }

            return response()->json($serializer->resolved($alias, $context));
        }

        return response()->json($serializer->variants($alias, $contextClass));
    }
}
