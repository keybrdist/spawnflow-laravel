<?php

namespace Spawnflow\Generator;

/**
 * Compiles a contract field descriptor + structured rules into a Zod
 * expression. The single owned definition of the rule → Zod mapping.
 *
 * serverOnly rules are never compiled — they surface as a trailing
 * `server:` comment so generated schemas are honest about what still
 * requires a server pass. Client rules without a Zod mapping surface as
 * an `unhandled:` comment rather than being silently dropped.
 */
class ZodCompiler
{
    /**
     * @param  array  $descriptor  Contract field descriptor (type, options, relation, ...).
     * @param  list<array{rule: string, params?: array, serverOnly?: bool}>  $rules
     */
    public static function compile(array $descriptor, array $rules): string
    {
        $byName = [];
        $serverOnly = [];
        $unhandled = [];

        foreach ($rules as $entry) {
            if ($entry['serverOnly'] ?? false) {
                $serverOnly[] = $entry['rule'];

                continue;
            }
            $byName[$entry['rule']] = $entry['params'] ?? [];
        }

        $expr = static::base($descriptor, $byName);
        $expr .= static::chain($descriptor, $byName, $unhandled);
        $expr .= static::presence($byName);

        $comments = [];
        if ($serverOnly !== []) {
            $comments[] = 'server: '.implode(', ', array_unique($serverOnly));
        }
        if ($unhandled !== []) {
            $comments[] = 'unhandled: '.implode(', ', array_unique($unhandled));
        }

        return $comments === [] ? $expr : $expr.' /* '.implode('; ', $comments).' */';
    }

    /**
     * @param  array<string, array>  $byName
     */
    protected static function base(array $descriptor, array $byName): string
    {
        $type = $descriptor['type'] ?? 'string';

        if ($type === 'enum') {
            return static::enumExpression($descriptor['options'] ?? []);
        }

        if ($type === 'relation') {
            $multiple = $descriptor['relation']['multiple'] ?? false;

            return $multiple ? 'z.array(z.number())' : 'z.number()';
        }

        if ($type === 'int') {
            return 'z.number().int()';
        }

        if ($type === 'float') {
            return 'z.number()';
        }

        if ($type === 'bool') {
            // on_off wire: logical booleans on write, 'on'/'off' on read.
            return ($descriptor['wire'] ?? null) === 'on_off'
                ? "z.union([z.boolean(), z.literal('on'), z.literal('off')])"
                : 'z.boolean()';
        }

        if ($type === 'json' || $type === 'file') {
            return 'z.unknown()';
        }

        // string / text / email / password / date / datetime — plus an
        // in-rule on a plain string collapses to an enum of its values.
        if (isset($byName['in']) && static::allStrings($byName['in'])) {
            return static::enumExpression(array_map(
                fn ($value) => ['value' => $value],
                $byName['in'],
            ));
        }

        return 'z.string()';
    }

    /**
     * @param  array<string, array>  $byName
     * @param  list<string>  $unhandled
     */
    protected static function chain(array $descriptor, array $byName, array &$unhandled): string
    {
        $type = $descriptor['type'] ?? 'string';
        $isString = in_array($type, ['string', 'text', 'email', 'password', 'date', 'datetime'], true)
            && ! isset($byName['in']);
        $isNumber = in_array($type, ['int', 'float', 'relation'], true) || isset($byName['integer']) || isset($byName['numeric']);

        $chain = '';

        if (($type === 'email' || isset($byName['email'])) && $isString) {
            $chain .= '.email()';
        }

        foreach (['url', 'uuid', 'ulid'] as $format) {
            if (isset($byName[$format]) && $isString) {
                $chain .= ".{$format}()";
            }
        }

        if (isset($byName['integer']) && $type !== 'int' && $type !== 'relation') {
            $chain .= '.int()';
        }

        if (isset($byName['regex']) && $isString) {
            $chain .= '.regex('.static::regexLiteral($byName['regex'][0]).')';
        }

        if (isset($byName['starts_with']) && $isString && count($byName['starts_with']) === 1) {
            $chain .= '.startsWith('.static::quote($byName['starts_with'][0]).')';
        }

        if (isset($byName['ends_with']) && $isString && count($byName['ends_with']) === 1) {
            $chain .= '.endsWith('.static::quote($byName['ends_with'][0]).')';
        }

        // Bounds: strings use min/max/length, numbers use gte/lte.
        [$min, $max] = static::bounds($byName);

        if (isset($byName['size'])) {
            $chain .= $isNumber
                ? '.gte('.$byName['size'][0].').lte('.$byName['size'][0].')'
                : '.length('.$byName['size'][0].')';
        }

        if ($min !== null) {
            $chain .= $isNumber ? ".gte({$min})" : ".min({$min})";
        } elseif (isset($byName['required']) && $isString && ! isset($byName['size'])) {
            // Laravel's required rejects empty strings.
            $chain .= '.min(1)';
        }

        if ($max !== null) {
            $chain .= $isNumber ? ".lte({$max})" : ".max({$max})";
        }

        if (isset($byName['accepted'])) {
            $chain = '';
        }

        // Anything client-safe we didn't map gets flagged, not dropped.
        $handled = [
            'required', 'nullable', 'sometimes', 'filled', 'string', 'integer', 'numeric',
            'boolean', 'array', 'json', 'date', 'email', 'url', 'uuid', 'ulid', 'in',
            'min', 'max', 'size', 'between', 'regex', 'starts_with', 'ends_with', 'accepted',
        ];
        foreach (array_keys($byName) as $name) {
            if (! in_array($name, $handled, true)) {
                $unhandled[] = $name;
            }
        }

        return $chain;
    }

    /**
     * Optionality. Laravel's `required|nullable` means the key must be PRESENT
     * but may be null — so `required` suppresses `.optional()` even when
     * nullable, otherwise the client accepts a missing key the server 422s.
     *
     * @param  array<string, array>  $byName
     */
    protected static function presence(array $byName): string
    {
        $present = isset($byName['required']) || isset($byName['accepted']);

        if (isset($byName['nullable'])) {
            return $present ? '.nullable()' : '.nullable().optional()';
        }

        return $present ? '' : '.optional()';
    }

    /**
     * @return array{0: int|float|null, 1: int|float|null}
     */
    protected static function bounds(array $byName): array
    {
        if (isset($byName['between'])) {
            return [$byName['between'][0], $byName['between'][1]];
        }

        return [
            $byName['min'][0] ?? null,
            $byName['max'][0] ?? null,
        ];
    }

    /**
     * @param  list<array{value: mixed}>  $options
     */
    protected static function enumExpression(array $options): string
    {
        $values = array_column($options, 'value');

        if ($values === []) {
            return 'z.never()';
        }

        if (static::allStrings($values)) {
            return 'z.enum(['.implode(', ', array_map(static::quote(...), $values)).'])';
        }

        $literals = array_map(
            fn ($value) => 'z.literal('.(is_string($value) ? static::quote($value) : $value).')',
            $values,
        );

        return count($literals) === 1 ? $literals[0] : 'z.union(['.implode(', ', $literals).'])';
    }

    protected static function allStrings(array $values): bool
    {
        return $values !== [] && array_filter($values, fn ($value) => ! is_string($value)) === [];
    }

    /**
     * Single-quoted JS string literal — escapes backslashes, quotes, and
     * every JS line terminator, mirroring TypeScriptGenerator::tsString().
     */
    protected static function quote(string $value): string
    {
        return "'".str_replace(
            ['\\', "'", "\r", "\n", "\u{2028}", "\u{2029}"],
            ['\\\\', "\\'", '\\r', '\\n', '\\u2028', '\\u2029'],
            $value,
        )."'";
    }

    /**
     * Laravel regex params arrive PCRE-delimited (/pattern/flags); emit as
     * a JS regex literal when the delimiters translate, else RegExp.
     */
    protected static function regexLiteral(string $pattern): string
    {
        if (preg_match('#^/(.*)/([a-z]*)$#s', $pattern, $matches)) {
            $flags = str_replace(['u', 'D', 'x', 'X'], '', $matches[2]);

            return '/'.$matches[1].'/'.$flags;
        }

        return 'new RegExp('.static::quote($pattern).')';
    }
}
