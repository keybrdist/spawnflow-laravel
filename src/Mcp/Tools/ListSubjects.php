<?php

namespace Spawnflow\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spawnflow\Mcp\Tools\Concerns\ResolvesSubjects;

/**
 * The registry, as data: every subject alias with whether it carries a
 * FieldContext and a FieldSet. Model class names ship only in the local
 * environment — deployed servers describe subjects by alias alone.
 */
class ListSubjects extends Tool
{
    use ResolvesSubjects;

    protected string $description = 'List every SpawnFlow subject alias with its capabilities (field descriptors, permission context). Start here to discover what the application exposes.';

    public function handle(): Response
    {
        $registry = $this->registry();
        $local = app()->environment('local');

        $subjects = [];
        foreach ($registry->all() as $alias => $modelClass) {
            $subject = [
                'alias' => $alias,
                'has_context' => $registry->contextFor($alias) !== null,
                'has_fields' => $registry->fieldsFor($alias) !== null,
            ];

            if ($local) {
                $subject['model'] = $modelClass;
            }

            $subjects[] = $subject;
        }

        return Response::json(['subjects' => $subjects]);
    }
}
