<?php

namespace Spawnflow\Schema;

use BackedEnum;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum as EnumRule;
use ReflectionProperty;
use Stringable;

/**
 * Serializes Laravel validation rules into the structured form the schema
 * contract uses: [{rule, params?, serverOnly?}].
 *
 * Rules a client can evaluate statically (via generated Zod or equivalent)
 * are emitted plain; everything else — database rules, rule objects,
 * closures, anything unknown — is flagged serverOnly so the frontend knows
 * a server pass remains authoritative for that rule.
 */
class RuleSerializer
{
    /**
     * Rules that map cleanly to a static client-side validator.
     */
    public const CLIENT_RULES = [
        'accepted', 'after', 'after_or_equal', 'alpha', 'alpha_dash', 'alpha_num',
        'array', 'before', 'before_or_equal', 'between', 'boolean', 'confirmed',
        'date', 'date_format', 'decimal', 'declined', 'digits', 'digits_between',
        'doesnt_end_with', 'doesnt_start_with', 'email', 'ends_with', 'filled',
        'in', 'integer', 'json', 'lowercase', 'max', 'min', 'not_in', 'not_regex',
        'nullable', 'numeric', 'regex', 'required', 'size', 'sometimes',
        'starts_with', 'string', 'ulid', 'uppercase', 'url', 'uuid',
    ];

    /**
     * Rules whose parameter must not be comma-split (may contain commas).
     */
    protected const SINGLE_PARAM_RULES = ['regex', 'not_regex', 'date_format'];

    /**
     * @param  string|array<int, mixed>  $rules
     * @return list<array{rule: string, params?: array, serverOnly?: bool}>
     */
    public static function serialize(string|array $rules): array
    {
        if (is_string($rules)) {
            $rules = $rules === '' ? [] : explode('|', $rules);
        }

        return array_values(array_map(
            fn (mixed $rule) => static::serializeOne($rule),
            $rules,
        ));
    }

    /**
     * @return array{rule: string, params?: array, serverOnly?: bool}
     */
    protected static function serializeOne(mixed $rule): array
    {
        if ($rule instanceof Closure) {
            return ['rule' => 'closure', 'serverOnly' => true];
        }

        if (is_object($rule)) {
            // Enum rules serialize to their in-rule form on every framework
            // major — Laravel 11's Enum object is not Stringable, so derive
            // the membership from the backing enum's cases directly.
            if ($rule instanceof EnumRule) {
                return static::serializeEnumRule($rule);
            }

            if ($rule instanceof Stringable) {
                return static::serializeString((string) $rule);
            }

            return [
                'rule' => Str::snake(class_basename($rule)),
                'serverOnly' => true,
            ];
        }

        return static::serializeString((string) $rule);
    }

    /**
     * @return array{rule: string, params: array}
     */
    protected static function serializeEnumRule(EnumRule $rule): array
    {
        $read = function (string $property) use ($rule): mixed {
            $ref = new ReflectionProperty($rule, $property);

            return $ref->getValue($rule);
        };

        /** @var class-string<BackedEnum> $type */
        $type = $read('type');
        $only = $read('only');
        $except = $read('except');

        $values = [];
        foreach ($type::cases() as $case) {
            if ($only !== [] && ! in_array($case, $only, true)) {
                continue;
            }
            if (in_array($case, $except, true)) {
                continue;
            }
            $values[] = $case->value;
        }

        return ['rule' => 'in', 'params' => $values];
    }

    /**
     * @return array{rule: string, params?: array, serverOnly?: bool}
     */
    protected static function serializeString(string $rule): array
    {
        [$name, $params] = array_pad(explode(':', $rule, 2), 2, null);
        $name = mb_strtolower($name);

        $entry = ['rule' => $name];

        if ($params !== null && $params !== '') {
            $entry['params'] = in_array($name, self::SINGLE_PARAM_RULES, true)
                ? [$params]
                : array_map(
                    fn (string $param) => static::castParam($param),
                    explode(',', $params),
                );
        }

        if (! in_array($name, self::CLIENT_RULES, true)) {
            $entry['serverOnly'] = true;
        }

        return $entry;
    }

    /**
     * Strip the quote-wrapping stringable rule objects emit (in:"a","b")
     * and cast numeric params to numbers.
     */
    protected static function castParam(string $param): string|int|float
    {
        if (strlen($param) >= 2 && str_starts_with($param, '"') && str_ends_with($param, '"')) {
            $param = substr($param, 1, -1);
        }

        return is_numeric($param) ? $param + 0 : $param;
    }
}
