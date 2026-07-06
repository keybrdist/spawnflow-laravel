<?php

namespace Spawnflow\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Exceptions\UnauthenticatedException;
use Spawnflow\Schema\FieldType;

/**
 * Serves {value, label} option pages for relation fields — the data source
 * behind searchable select/combobox widgets.
 *
 * GET /spawnflow/options/{subject}/{field}?q=&page=&per_page=
 *
 * Options are scoped to the caller's ownership when the field is scoped
 * (the default) and the related table has the ownership column; fields
 * declared unscoped() serve globally (shared lookups).
 */
class OptionsController extends Controller
{
    public function show(Request $request, string $subject, string $fieldName): JsonResponse
    {
        $registry = app(SubjectRegistry::class);

        $alias = mb_strtolower($subject);
        if (! array_key_exists($alias, $registry->all())) {
            return response()->json(['error' => "Unknown subject: {$subject}"], 404);
        }

        $user = $request->user();
        if ($user === null) {
            throw new UnauthenticatedException;
        }

        $fieldSet = $registry->fieldsFor($alias);
        $field = $fieldSet !== null ? $fieldSet::field($fieldName) : null;

        if ($field === null || $field->type !== FieldType::Relation) {
            return response()->json(['error' => "No relation field '{$fieldName}' on {$alias}"], 404);
        }

        $related = new ($field->getRelatedModel());
        $display = $field->getDisplayColumn();
        $query = $related->newQuery();

        if ($field->isScoped()) {
            $ownershipColumn = config('spawnflow.ownership_column', 'ownerId');
            $userKey = config('spawnflow.user_key', 'id');
            $columns = $related->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($related->getTable());

            // Fail CLOSED: a scoped field whose related table lacks the
            // ownership column is a configuration error — silently skipping
            // the filter would serve every tenant's rows. Declare the field
            // ->unscoped() explicitly for shared lookup tables.
            if (! in_array($ownershipColumn, $columns, true)) {
                throw new RuntimeException(
                    "Scoped options field '{$fieldName}' on '{$alias}': related table ".
                    "'{$related->getTable()}' has no '{$ownershipColumn}' column. ".
                    'Declare the field ->unscoped() if it is a shared lookup.',
                );
            }

            $query->where($ownershipColumn, $user->{$userKey});
        }

        $q = (string) $request->query('q', '');
        if ($q !== '' && $field->isSearchable()) {
            $query->where($display, 'like', '%'.$q.'%');
        }

        $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
        $page = max((int) $request->query('page', 1), 1);

        // Fetch one extra row to know whether another page exists.
        $results = $query->orderBy($display)
            ->skip(($page - 1) * $perPage)
            ->take($perPage + 1)
            ->get();

        $hasMore = $results->count() > $perPage;

        $options = $results->take($perPage)
            ->map(fn ($model) => [
                'value' => $model->getKey(),
                'label' => (string) $model->{$display},
            ])
            ->values();

        return response()->json([
            'options' => $options,
            'page' => $page,
            'next_page' => $hasMore ? $page + 1 : null,
        ]);
    }
}
