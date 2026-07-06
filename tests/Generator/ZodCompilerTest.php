<?php

use Spawnflow\Generator\ZodCompiler;

function zod(array $descriptor, array $rules): string
{
    return ZodCompiler::compile($descriptor, $rules);
}

test('compiles required string with bounds', function (): void {
    expect(zod(['type' => 'string'], [
        ['rule' => 'required'], ['rule' => 'string'], ['rule' => 'max', 'params' => [255]],
    ]))->toBe('z.string().min(1).max(255)');
});

test('optional and nullable presence', function (): void {
    expect(zod(['type' => 'string'], [['rule' => 'string']]))
        ->toBe('z.string().optional()')
        ->and(zod(['type' => 'text'], [['rule' => 'nullable'], ['rule' => 'string']]))
        ->toBe('z.string().nullable().optional()');
});

test('compiles enum descriptors to z.enum', function (): void {
    $descriptor = ['type' => 'enum', 'options' => [
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'published', 'label' => 'Live'],
    ]];

    expect(zod($descriptor, [['rule' => 'in', 'params' => ['draft', 'published']]]))
        ->toBe("z.enum(['draft', 'published']).optional()");
});

test('compiles numeric enums to literal unions', function (): void {
    $descriptor = ['type' => 'enum', 'options' => [['value' => 1], ['value' => 2]]];

    expect(zod($descriptor, [['rule' => 'required']]))
        ->toBe('z.union([z.literal(1), z.literal(2)])');
});

test('in-rule on a plain string collapses to z.enum', function (): void {
    expect(zod(['type' => 'string'], [
        ['rule' => 'required'], ['rule' => 'in', 'params' => ['a', 'b']],
    ]))->toBe("z.enum(['a', 'b'])");
});

test('numbers use int/gte/lte', function (): void {
    expect(zod(['type' => 'int'], [
        ['rule' => 'required'], ['rule' => 'integer'], ['rule' => 'between', 'params' => [1, 10]],
    ]))->toBe('z.number().int().gte(1).lte(10)');
});

test('relations compile to number or number array', function (): void {
    expect(zod(['type' => 'relation', 'relation' => ['multiple' => false]], [['rule' => 'required']]))
        ->toBe('z.number()')
        ->and(zod(['type' => 'relation', 'relation' => ['multiple' => true]], []))
        ->toBe('z.array(z.number()).optional()');
});

test('email and format rules chain', function (): void {
    expect(zod(['type' => 'email'], [['rule' => 'required'], ['rule' => 'email']]))
        ->toBe('z.string().email().min(1)');
});

test('regex emits a JS regex literal', function (): void {
    expect(zod(['type' => 'string'], [
        ['rule' => 'required'], ['rule' => 'regex', 'params' => ['/^[A-Z]+$/']],
    ]))->toBe('z.string().regex(/^[A-Z]+$/).min(1)');
});

test('serverOnly rules surface as comments, never compiled', function (): void {
    $expr = zod(['type' => 'email'], [
        ['rule' => 'required'], ['rule' => 'email'],
        ['rule' => 'unique', 'params' => ['users', 'email'], 'serverOnly' => true],
    ]);

    expect($expr)->toBe('z.string().email().min(1) /* server: unique */');
});

test('unmapped client rules surface as unhandled comments', function (): void {
    $expr = zod(['type' => 'password'], [
        ['rule' => 'required'], ['rule' => 'confirmed'],
    ]);

    expect($expr)->toContain('/* unhandled: confirmed */');
});

test('boolean accepted compiles cleanly', function (): void {
    expect(zod(['type' => 'bool'], [['rule' => 'accepted']]))->toBe('z.boolean()');
});

test('required nullable keeps the key present', function (): void {
    // Laravel required|nullable = key must be present, value may be null.
    // .optional() here would let the client accept a payload the server 422s.
    expect(zod(['type' => 'string'], [['rule' => 'required'], ['rule' => 'nullable'], ['rule' => 'string']]))
        ->toBe('z.string().min(1).nullable()')
        ->not->toContain('.optional()');
});
