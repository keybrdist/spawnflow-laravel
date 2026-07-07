<?php

use Spawnflow\Eligibility\Condition;
use Spawnflow\Eligibility\InvalidConditionException;

$fixtures = json_decode(
    file_get_contents(__DIR__.'/../../resources/conformance/eligibility-fixtures.json'),
    associative: true,
);

test('conformance fixtures', function (array|bool $condition, array $data, bool|string $expect): void {
    if ($expect === 'error') {
        expect(fn () => Condition::passes($condition, $data))
            ->toThrow(InvalidConditionException::class);

        return;
    }

    expect(Condition::passes($condition, $data))->toBe($expect);
})->with(
    collect($fixtures['cases'])->mapWithKeys(fn (array $case) => [
        $case['name'] => [$case['condition'], $case['data'], $case['expect']],
    ])->all(),
);

test('references collects var and missing names, root segment of dot paths, deduplicated', function (): void {
    $condition = [
        'and' => [
            ['==' => [['var' => 'country'], 'DE']],
            ['>' => [['var' => 'seats.min'], 3]],
            ['missing' => ['country', 'vat_id']],
            ['==' => [['var' => ['optional', 'fallback']], 'fallback']],
        ],
    ];

    expect(Condition::references($condition))
        ->toBe(['country', 'seats', 'vat_id', 'optional']);
});

test('references on a literal condition is empty', function (): void {
    expect(Condition::references(true))->toBe([]);
});
