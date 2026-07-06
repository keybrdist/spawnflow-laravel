<?php

use Illuminate\Validation\Rule;
use Spawnflow\Schema\RuleSerializer;

test('parses a pipe-delimited rule string', function (): void {
    expect(RuleSerializer::serialize('required|string|max:255'))->toBe([
        ['rule' => 'required'],
        ['rule' => 'string'],
        ['rule' => 'max', 'params' => [255]],
    ]);
});

test('casts numeric params and keeps string params', function (): void {
    expect(RuleSerializer::serialize('between:1,10'))->toBe([
        ['rule' => 'between', 'params' => [1, 10]],
    ])->and(RuleSerializer::serialize('in:draft,published'))->toBe([
        ['rule' => 'in', 'params' => ['draft', 'published']],
    ]);
});

test('does not comma-split regex params', function (): void {
    expect(RuleSerializer::serialize('regex:/^[a,b]{1,3}$/'))->toBe([
        ['rule' => 'regex', 'params' => ['/^[a,b]{1,3}$/']],
    ]);
});

test('flags database and unknown rules as serverOnly', function (): void {
    expect(RuleSerializer::serialize('unique:users,email'))->toBe([
        ['rule' => 'unique', 'params' => ['users', 'email'], 'serverOnly' => true],
    ])->and(RuleSerializer::serialize('some_custom_rule'))->toBe([
        ['rule' => 'some_custom_rule', 'serverOnly' => true],
    ]);
});

test('serializes array rules including stringable rule objects', function (): void {
    $rules = RuleSerializer::serialize(['required', Rule::in(['a', 'b'])]);

    expect($rules[0])->toBe(['rule' => 'required'])
        ->and($rules[1])->toBe(['rule' => 'in', 'params' => ['a', 'b']]);
});

test('serializes an Enum rule object via its in-rule string form', function (): void {
    $rules = RuleSerializer::serialize([Rule::enum(\Spawnflow\Tests\Fixtures\PostStatus::class)]);

    expect($rules[0])->toBe(['rule' => 'in', 'params' => ['draft', 'published']]);
});

test('serializes closures and non-stringable objects as serverOnly', function (): void {
    $custom = new class implements \Illuminate\Contracts\Validation\ValidationRule
    {
        public function validate(string $attribute, mixed $value, Closure $fail): void {}
    };

    $rules = RuleSerializer::serialize([
        fn ($attribute, $value, $fail) => null,
        $custom,
    ]);

    expect($rules[0])->toBe(['rule' => 'closure', 'serverOnly' => true])
        ->and($rules[1]['serverOnly'])->toBeTrue();
});

test('empty rules serialize to an empty list', function (): void {
    expect(RuleSerializer::serialize(''))->toBe([])
        ->and(RuleSerializer::serialize([]))->toBe([]);
});
