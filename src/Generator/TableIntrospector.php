<?php

namespace Spawnflow\Generator;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Reads a table's real columns and foreign keys and proposes Field
 * descriptor lines for spawnflow:resource --generate.
 *
 * Inference is a make-time scaffold ONLY: the generated file is the
 * canonical declaration and is meant to be reviewed and edited — schema
 * drift stays visible in code, never silently re-inferred at runtime.
 */
class TableIntrospector
{
    protected const SKIP = ['created_at', 'updated_at', 'deleted_at', 'remember_token'];

    /**
     * @return array{lines: string, names: list<string>, visible: list<string>}
     */
    public function introspect(string $table, ?string $connection = null): array
    {
        $ownership = config('spawnflow.ownership_column', 'ownerId');
        $foreign = $this->foreignKeyMap($table, $connection);

        $lines = [];
        $names = [];
        $visible = ['id'];

        foreach (Schema::connection($connection)->getColumns($table) as $column) {
            $name = $column['name'];

            if ($column['auto_increment'] ?? false) {
                continue;
            }
            if (in_array($name, self::SKIP, true) || $name === $ownership) {
                if (in_array($name, ['created_at', 'updated_at'], true)) {
                    $visible[] = $name;
                }

                continue;
            }

            $lines[] = $this->fieldLine($name, $column, $foreign[$name] ?? null);
            $names[] = $name;
            $visible[] = $name;
        }

        return [
            'lines' => implode("\n", array_map(fn (string $line) => '            '.$line, $lines)),
            'names' => $names,
            'visible' => $visible,
        ];
    }

    /**
     * @param  array{name: string, type: string, type_name: string, nullable: bool, default: mixed}  $column
     */
    protected function fieldLine(string $name, array $column, ?string $foreignTable): string
    {
        $line = $this->constructor($name, $column, $foreignTable);

        if ($column['nullable']) {
            $line .= '->nullable()';
        } elseif ($column['default'] === null) {
            $line .= "->rules('required')";
        }

        if ($column['default'] !== null && is_scalar($column['default']) && $column['type_name'] !== 'timestamp') {
            $default = is_string($column['default']) ? "'".addslashes(trim($column['default'], "'"))."'" : var_export($column['default'], true);
            $line .= "->default({$default})";
        }

        return $line.',';
    }

    protected function constructor(string $name, array $column, ?string $foreignTable): string
    {
        if ($foreignTable !== null) {
            $model = 'App\\Models\\'.Str::studly(Str::singular($foreignTable));

            if (class_exists($model)) {
                return "Field::belongsTo('{$name}', \\{$model}::class)";
            }

            return "Field::int('{$name}') /* FK to {$foreignTable} — point Field::belongsTo() at its model */";
        }

        // Email only when the column IS an email address — 'email',
        // 'contact_email', 'billingEmail' — not merely email-related
        // ('emailSubject', 'emailBodyHtml' are strings).
        if (preg_match('/(^|_)email$|Email$/', $name) === 1 || str_contains($name, 'email_address') || str_contains($name, 'emailAddress')) {
            return "Field::email('{$name}')";
        }
        if ($name === 'password') {
            return "Field::password('{$name}')";
        }

        // MySQL enum columns: enum('a','b') — scaffold membership as an
        // in: rule; promote to Field::enum() with a backed enum when one
        // exists.
        if ($column['type_name'] === 'enum' && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $column['type'], $matches)) {
            $values = implode(',', $matches[1]);

            return "Field::string('{$name}')->rules('in:{$values}') /* enum column — consider Field::enum() with a backed enum */";
        }

        // Legacy on/off flags: varchar columns defaulting to 'on'/'off'
        // are booleans wearing a wire format — declare them as bool with
        // the coercion attached, one declaration for both sides.
        $default = is_string($column['default'] ?? null) ? trim($column['default'], "'") : null;
        if (in_array($column['type_name'], ['varchar', 'char', 'string'], true) && in_array($default, ['on', 'off'], true)) {
            return "Field::bool('{$name}')->wire('on_off')";
        }

        return match ($column['type_name']) {
            'varchar', 'char', 'string' => "Field::string('{$name}')",
            'text', 'tinytext', 'mediumtext', 'longtext' => "Field::text('{$name}')",
            'tinyint' => str_contains($column['type'], 'tinyint(1)')
                ? "Field::bool('{$name}')"
                : "Field::int('{$name}')",
            'boolean', 'bool' => "Field::bool('{$name}')",
            'int', 'integer', 'bigint', 'smallint', 'mediumint' => "Field::int('{$name}')",
            'decimal', 'numeric', 'float', 'double', 'real' => "Field::float('{$name}')",
            'date' => "Field::date('{$name}')",
            'datetime', 'timestamp' => "Field::datetime('{$name}')",
            'json', 'jsonb' => "Field::json('{$name}')",
            default => "Field::string('{$name}') /* unrecognized column type: {$column['type_name']} */",
        };
    }

    /**
     * column name → foreign table, from real FK constraints.
     *
     * @return array<string, string>
     */
    protected function foreignKeyMap(string $table, ?string $connection = null): array
    {
        $map = [];
        foreach (Schema::connection($connection)->getForeignKeys($table) as $key) {
            if (count($key['columns']) === 1) {
                $map[$key['columns'][0]] = $key['foreign_table'];
            }
        }

        return $map;
    }
}
