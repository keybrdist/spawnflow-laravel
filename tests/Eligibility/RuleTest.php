<?php

use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Eligibility\Rule;

test('show is visible iff the condition passes', function (): void {
    $rule = Rule::show(['==' => [['var' => 'type'], 'business']]);

    expect($rule->visible(['type' => 'business']))->toBeTrue()
        ->and($rule->visible(['type' => 'personal']))->toBeFalse()
        ->and($rule->enabled(['type' => 'business']))->toBeNull();
});

test('hide inverts polarity', function (): void {
    $rule = Rule::hide(['==' => [['var' => 'locked'], true]]);

    expect($rule->visible(['locked' => true]))->toBeFalse()
        ->and($rule->visible(['locked' => false]))->toBeTrue();
});

test('enable and disable govern editability, not visibility', function (): void {
    $enable = Rule::enable(['==' => [['var' => 'status'], 'draft']]);
    $disable = Rule::disable(['==' => [['var' => 'status'], 'published']]);

    expect($enable->enabled(['status' => 'draft']))->toBeTrue()
        ->and($enable->enabled(['status' => 'published']))->toBeFalse()
        ->and($enable->visible(['status' => 'draft']))->toBeNull()
        ->and($disable->enabled(['status' => 'published']))->toBeFalse()
        ->and($disable->enabled(['status' => 'draft']))->toBeTrue();
});

test('evaluation errors fail closed regardless of polarity', function (): void {
    $broken = ['unknown_op' => []];

    // hide with an erroring condition must NOT fall through to visible.
    expect(Rule::hide($broken)->visible([]))->toBeFalse()
        ->and(Rule::show($broken)->visible([]))->toBeFalse()
        ->and(Rule::disable($broken)->enabled([]))->toBeFalse()
        ->and(Rule::enable($broken)->enabled([]))->toBeFalse();
});

test('toArray emits the wire envelope', function (): void {
    $condition = ['==' => [['var' => 'a'], 1]];

    expect(Rule::show($condition)->toArray())
        ->toBe(['effect' => 'show', 'condition' => $condition]);
});

test('eligibility resolve ANDs verdicts per axis and defaults to eligible', function (): void {
    $rules = [
        Rule::show(['==' => [['var' => 'type'], 'business']]),
        Rule::enable(['>' => [['var' => 'seats'], 5]]),
    ];

    expect(Eligibility::resolve($rules, ['type' => 'business', 'seats' => 10]))
        ->toBe(['visible' => true, 'enabled' => true])
        ->and(Eligibility::resolve($rules, ['type' => 'personal', 'seats' => 10]))
        ->toBe(['visible' => false, 'enabled' => true])
        ->and(Eligibility::resolve($rules, ['type' => 'business', 'seats' => 2]))
        ->toBe(['visible' => true, 'enabled' => false])
        ->and(Eligibility::resolve([], ['anything' => 1]))
        ->toBe(['visible' => true, 'enabled' => true]);
});
