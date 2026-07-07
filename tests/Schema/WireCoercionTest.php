<?php

use Illuminate\Http\Request;
use Spawnflow\Flow;
use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

// ---------------------------------------------------------------
// Field::coerceWire — per-format unit behavior
// ---------------------------------------------------------------

test('on_off coerces logical booleans and is idempotent for wire values', function (): void {
    $field = Field::bool('active')->wire('on_off');

    expect($field->coerceWire(true))->toBe('on')
        ->and($field->coerceWire(false))->toBe('off')
        ->and($field->coerceWire(1))->toBe('on')
        ->and($field->coerceWire('true'))->toBe('on')
        ->and($field->coerceWire(''))->toBe('off')
        // Already-wire payloads (legacy transforms) pass through.
        ->and($field->coerceWire('on'))->toBe('on')
        ->and($field->coerceWire('off'))->toBe('off')
        ->and($field->coerceWire(null))->toBeNull();
});

test('csv joins scalar arrays and json-encodes nested elements', function (): void {
    $field = Field::string('genres')->wire('csv');

    expect($field->coerceWire([1, 43]))->toBe('1,43')
        ->and($field->coerceWire(['a', null, 'b']))->toBe('a,,b')
        ->and($field->coerceWire([['x' => 1], 'b']))->toBe('{"x":1},b')
        ->and($field->coerceWire([]))->toBe('')
        ->and($field->coerceWire('1,43'))->toBe('1,43');
});

test('json encodes arrays and objects, passes strings through', function (): void {
    $field = Field::json('extendedData')->wire('json');

    expect($field->coerceWire(['a' => 1]))->toBe('{"a":1}')
        ->and($field->coerceWire((object) ['a' => 1]))->toBe('{"a":1}')
        ->and($field->coerceWire('{"a":1}'))->toBe('{"a":1}')
        ->and($field->coerceWire(null))->toBeNull();
});

test('fields without a wire format never touch the value', function (): void {
    expect(Field::bool('flag')->coerceWire(true))->toBeTrue()
        ->and(Field::string('name')->coerceWire('x'))->toBe('x');
});

// ---------------------------------------------------------------
// Flow write path — one declaration drives storage coercion
// ---------------------------------------------------------------

class WirePostFields extends FieldSet
{
    public static function fields(): array
    {
        return [
            Field::string('title')->rules('required'),
            // status stored as a string; a boolean-ish wire example on a
            // real column: body stored csv.
            Field::string('body')->wire('csv')->nullable(),
            Field::bool('status')->wire('on_off'),
        ];
    }
}

test('save stores wire shapes for logical payloads', function (): void {
    config()->set('spawnflow.fields.posts', WirePostFields::class);
    config()->set('spawnflow.contexts.posts', null);

    $user = User::create(['name' => 'W', 'email' => uniqid().'@x.com', 'roles' => '']);
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $user);

    (new Flow)
        ->spawn($request)
        ->auth()
        ->resolve('posts')
        ->save(['title' => 'T', 'body' => ['drum', 'bass'], 'status' => true]);

    $post = Post::query()->latest('id')->first();
    expect($post->body)->toBe('drum,bass')
        ->and($post->status)->toBe('on');
});

test('validate sees the stored shape so storage-format rules hold', function (): void {
    config()->set('spawnflow.fields.posts', WirePostFields::class);
    config()->set('spawnflow.contexts.posts', null);

    $user = User::create(['name' => 'W2', 'email' => uniqid().'@x.com', 'roles' => '']);
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $user);

    // in:on,off targets storage; a boolean payload must satisfy it via coercion.
    (new Flow)
        ->spawn($request)
        ->auth()
        ->resolve('posts')
        ->validate(['status' => 'in:on,off'], ['status' => true]);
})->throwsNoExceptions();
