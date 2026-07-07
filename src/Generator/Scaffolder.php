<?php

namespace Spawnflow\Generator;

/**
 * The single stub pipeline behind make:spawnflow-context and
 * spawnflow:resource — one token vocabulary, one renderer, so the two
 * commands can never drift on what a generated context/fieldset looks
 * like. Published stubs (stubs/spawnflow/*) override the package's.
 */
class Scaffolder
{
    public static function stub(string $name): string
    {
        $published = base_path("stubs/spawnflow/{$name}");

        return file_get_contents(
            is_file($published) ? $published : __DIR__.'/../../stubs/'.$name,
        );
    }

    /**
     * Render the context enum stub.
     *
     * @param  array{ownerEditable: string, ownerValidation: string, ownerVisible: string, viewerVisible: string}  $lists  Pre-indented body blocks.
     */
    public static function contextEnum(string $namespace, string $class, array $lists): string
    {
        return self::fill(self::stub('context-enum.stub'), [
            'namespace' => $namespace,
            'class' => $class,
            ...$lists,
        ]);
    }

    /**
     * Render the FieldSet stub with its self-registering attribute.
     *
     * @param  class-string  $model
     * @param  class-string|null  $context
     */
    public static function fieldSet(
        string $namespace,
        string $class,
        string $alias,
        string $model,
        ?string $context,
        string $fieldLines,
    ): string {
        return self::fill(self::stub('fieldset.stub'), [
            'namespace' => $namespace,
            'class' => $class,
            'alias' => $alias,
            'model' => ltrim($model, '\\'),
            'contextArg' => $context !== null ? ', context: \\'.ltrim($context, '\\').'::class' : '',
            'fields' => $fieldLines,
        ]);
    }

    /**
     * Quoted, indented array-item lines: ['a', 'b'] → "    'a',\n    'b',".
     *
     * @param  list<string>  $items
     */
    public static function lines(array $items, int $indent): string
    {
        $pad = str_repeat(' ', $indent);

        return implode("\n", array_map(
            fn (string $item) => $pad."'".addslashes($item)."',",
            $items,
        ));
    }

    /**
     * The default (uncustomized) context list blocks — commented examples,
     * matching what make:spawnflow-context scaffolds bare.
     *
     * @return array{ownerEditable: string, ownerValidation: string, ownerVisible: string, viewerVisible: string}
     */
    public static function defaultContextLists(): array
    {
        return [
            'ownerEditable' => "                // 'title',\n                // 'description',",
            'ownerValidation' => "                // 'title' => 'required|string|max:255',",
            'ownerVisible' => "                'id',\n                // 'title',\n                // 'createdAt',",
            'viewerVisible' => "                'id',\n                // 'title',",
        ];
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected static function fill(string $stub, array $replacements): string
    {
        foreach ($replacements as $token => $value) {
            $stub = str_replace('{{ '.$token.' }}', $value, $stub);
        }

        return $stub;
    }
}
