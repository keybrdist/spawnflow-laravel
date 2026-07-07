<?php

namespace Spawnflow\Eligibility;

/**
 * The restricted JSON Logic evaluator behind eligibility rules.
 *
 * Deliberately NOT the full JSON Logic spec: a fixed operator allowlist,
 * strict equality (`==` means `===`), and explicit truthiness shared with
 * the JS evaluator in @spawnflow/core. Anything outside the allowlist
 * throws InvalidConditionException, which Rule turns into a fail-closed
 * outcome. The cross-runtime behavior is pinned by
 * resources/conformance/eligibility-fixtures.json — both evaluators run
 * the same cases.
 *
 * Operators: ==, !=, >, <, >=, <= (numeric only), and, or, !, in, var,
 * missing. `var` supports dot paths and an optional default:
 * {"var": "a.b"} or {"var": ["a.b", fallback]}. A var without a default
 * referencing an absent key throws — declared fields are always present
 * (null when unset) in the data both runtimes evaluate against, so this
 * only bites undeclared references, which the schema serializer already
 * rejects at declaration time.
 */
final class Condition
{
    private const MISSING = "\0spawnflow.missing\0";

    /**
     * Whether the condition passes against the given data.
     *
     * @param  array<string, mixed>|bool  $condition
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidConditionException
     */
    public static function passes(array|bool $condition, array $data): bool
    {
        return self::truthy(self::evaluate($condition, $data));
    }

    /**
     * Field names referenced by a condition's var/missing nodes.
     *
     * @param  array<string, mixed>|bool  $condition
     * @return list<string>
     */
    public static function references(array|bool $condition): array
    {
        $refs = [];
        self::collectReferences($condition, $refs);

        return array_values(array_unique($refs));
    }

    /**
     * Explicit truthiness, identical in both runtimes: null and false are
     * falsy, numbers are truthy unless zero, strings unless empty (so "0"
     * IS truthy, unlike PHP's cast), arrays unless empty.
     */
    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0,
            is_string($value) => $value !== '',
            is_array($value) => $value !== [],
            default => throw new InvalidConditionException('Cannot coerce value of type '.get_debug_type($value).' to boolean'),
        };
    }

    private static function evaluate(mixed $node, array $data): mixed
    {
        if ($node === null || is_scalar($node)) {
            return $node;
        }

        if (! is_array($node)) {
            throw new InvalidConditionException('Malformed condition node of type '.get_debug_type($node));
        }

        // A plain list (not a single-key operator map) evaluates element-wise.
        if (array_is_list($node)) {
            return array_map(fn ($item) => self::evaluate($item, $data), $node);
        }

        if (count($node) !== 1) {
            throw new InvalidConditionException('Operator nodes must have exactly one key, got: '.implode(', ', array_keys($node)));
        }

        $operator = array_key_first($node);
        $args = $node[$operator];

        return match ($operator) {
            'var' => self::evaluateVar($args, $data),
            'missing' => self::evaluateMissing($args, $data),
            '==' => self::binary($args, $data, fn ($a, $b) => $a === $b),
            '!=' => self::binary($args, $data, fn ($a, $b) => $a !== $b),
            '>' => self::numeric($args, $data, fn ($a, $b) => $a > $b),
            '<' => self::numeric($args, $data, fn ($a, $b) => $a < $b),
            '>=' => self::numeric($args, $data, fn ($a, $b) => $a >= $b),
            '<=' => self::numeric($args, $data, fn ($a, $b) => $a <= $b),
            'and' => self::logical($args, $data, requireAll: true),
            'or' => self::logical($args, $data, requireAll: false),
            '!' => ! self::truthy(self::evaluate(is_array($args) && array_is_list($args) && count($args) === 1 ? $args[0] : $args, $data)),
            'in' => self::evaluateIn($args, $data),
            default => throw new InvalidConditionException("Unknown operator: {$operator}"),
        };
    }

    private static function evaluateVar(mixed $args, array $data): mixed
    {
        [$path, $default] = is_array($args)
            ? [$args[0] ?? null, count($args) > 1 ? $args[1] : self::MISSING]
            : [$args, self::MISSING];

        if (! is_string($path) || $path === '') {
            throw new InvalidConditionException('var expects a non-empty string path');
        }

        $value = self::extract($path, $data);

        if ($value === self::MISSING) {
            if ($default === self::MISSING) {
                throw new InvalidConditionException("var references absent key: {$path}");
            }

            return $default;
        }

        return $value;
    }

    /**
     * The names among the arguments absent from the data. Truthy when any
     * are missing — the one operator that never throws on absence, since
     * testing absence is its purpose.
     *
     * @return list<string>
     */
    private static function evaluateMissing(mixed $args, array $data): array
    {
        $names = is_array($args) ? $args : [$args];

        $absent = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                throw new InvalidConditionException('missing expects string field names');
            }
            if (self::extract($name, $data) === self::MISSING) {
                $absent[] = $name;
            }
        }

        return $absent;
    }

    private static function evaluateIn(mixed $args, array $data): bool
    {
        [$needle, $haystack] = self::operands($args, $data, 'in');

        if (is_array($haystack)) {
            return in_array($needle, $haystack, true);
        }

        if (is_string($haystack) && is_string($needle)) {
            return $needle !== '' && str_contains($haystack, $needle);
        }

        throw new InvalidConditionException('in expects an array haystack, or string needle and haystack');
    }

    private static function binary(mixed $args, array $data, callable $compare): bool
    {
        return $compare(...self::operands($args, $data, 'comparison'));
    }

    private static function numeric(mixed $args, array $data, callable $compare): bool
    {
        [$a, $b] = self::operands($args, $data, 'numeric comparison');

        if (! is_int($a) && ! is_float($a) || ! is_int($b) && ! is_float($b)) {
            throw new InvalidConditionException('Numeric comparison requires numbers, got '.get_debug_type($a).' and '.get_debug_type($b));
        }

        return $compare($a, $b);
    }

    private static function logical(mixed $args, array $data, bool $requireAll): bool
    {
        if (! is_array($args) || ! array_is_list($args) || $args === []) {
            throw new InvalidConditionException('and/or expect a non-empty list of conditions');
        }

        foreach ($args as $arg) {
            $truthy = self::truthy(self::evaluate($arg, $data));

            if ($requireAll && ! $truthy) {
                return false;
            }
            if (! $requireAll && $truthy) {
                return true;
            }
        }

        return $requireAll;
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private static function operands(mixed $args, array $data, string $operator): array
    {
        if (! is_array($args) || ! array_is_list($args) || count($args) !== 2) {
            throw new InvalidConditionException("{$operator} expects exactly two operands");
        }

        return [self::evaluate($args[0], $data), self::evaluate($args[1], $data)];
    }

    /**
     * Dot-path lookup distinguishing "absent" from "present but null".
     */
    private static function extract(string $path, array $data): mixed
    {
        $current = $data;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return self::MISSING;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private static function collectReferences(mixed $node, array &$refs): void
    {
        if (! is_array($node)) {
            return;
        }

        if (! array_is_list($node) && count($node) === 1) {
            $operator = array_key_first($node);
            $args = $node[$operator];

            if ($operator === 'var') {
                $path = is_array($args) ? ($args[0] ?? null) : $args;
                if (is_string($path) && $path !== '') {
                    $refs[] = strtok($path, '.');
                }

                return;
            }

            if ($operator === 'missing') {
                foreach (is_array($args) ? $args : [$args] as $name) {
                    if (is_string($name)) {
                        $refs[] = strtok($name, '.');
                    }
                }

                return;
            }

            self::collectReferences($args, $refs);

            return;
        }

        foreach ($node as $child) {
            self::collectReferences($child, $refs);
        }
    }
}
