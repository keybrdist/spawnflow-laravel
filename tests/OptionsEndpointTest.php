<?php

use Spawnflow\Tests\Fixtures\LookupFields;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

function optionsUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'Options User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ], $attrs));
}

test('options are scoped to the caller and shaped as value/label pairs', function (): void {
    $me = optionsUser();
    $other = optionsUser();

    Post::create(['owner_id' => $me->id, 'title' => 'Alpha', 'status' => 'draft']);
    Post::create(['owner_id' => $me->id, 'title' => 'Beta', 'status' => 'draft']);
    Post::create(['owner_id' => $other->id, 'title' => 'Theirs', 'status' => 'draft']);

    $this->actingAs($me);

    $response = $this->getJson('/spawnflow/options/articles/post_id')->assertOk();

    expect($response->json('options'))->toHaveCount(2)
        ->and(array_column($response->json('options'), 'label'))->toBe(['Alpha', 'Beta'])
        ->and($response->json('options.0'))->toHaveKeys(['value', 'label'])
        ->and($response->json('next_page'))->toBeNull();
});

test('searchable fields filter by q on the display column', function (): void {
    $me = optionsUser();
    Post::create(['owner_id' => $me->id, 'title' => 'Quarterly report', 'status' => 'draft']);
    Post::create(['owner_id' => $me->id, 'title' => 'Weekly notes', 'status' => 'draft']);

    $this->actingAs($me);

    $labels = array_column(
        $this->getJson('/spawnflow/options/articles/post_id?q=Quarter')->json('options'),
        'label',
    );

    expect($labels)->toBe(['Quarterly report']);
});

test('options paginate with next_page', function (): void {
    $me = optionsUser();
    foreach (range(1, 5) as $i) {
        Post::create(['owner_id' => $me->id, 'title' => "Post {$i}", 'status' => 'draft']);
    }

    $this->actingAs($me);

    $first = $this->getJson('/spawnflow/options/articles/post_id?per_page=2')->json();
    expect($first['options'])->toHaveCount(2)
        ->and($first['next_page'])->toBe(2);

    $last = $this->getJson('/spawnflow/options/articles/post_id?per_page=2&page=3')->json();
    expect($last['options'])->toHaveCount(1)
        ->and($last['next_page'])->toBeNull();
});

test('non-relation and unknown fields return 404', function (): void {
    $this->actingAs(optionsUser());

    $this->getJson('/spawnflow/options/articles/title')->assertNotFound();
    $this->getJson('/spawnflow/options/articles/nope')->assertNotFound();
    $this->getJson('/spawnflow/options/unknown/post_id')->assertNotFound();
});

test('options require authentication', function (): void {
    $this->getJson('/spawnflow/options/articles/post_id')->assertStatus(401);
});

test('relation descriptors carry options_url when routes are enabled', function (): void {
    $this->actingAs(optionsUser());

    $this->getJson('/spawnflow/schema/articles')
        ->assertJsonPath('fields.post_id.relation.options_url', '/spawnflow/options/articles/post_id');
});

test('scoped options fail closed when the related table lacks the ownership column', function (): void {
    config()->set('spawnflow.subjects', array_merge(config('spawnflow.subjects'), [
        'lookups' => Post::class,
    ]));
    config()->set('spawnflow.fields', array_merge(config('spawnflow.fields'), [
        'lookups' => LookupFields::class,
    ]));

    $me = optionsUser();
    optionsUser(); // another tenant's user that must never leak

    $this->actingAs($me);
    $this->withoutExceptionHandling();

    // users table has no owner_id column; the field is scoped by default —
    // serving unscoped rows here would leak every tenant. Must throw, not
    // silently fail open.
    $this->getJson('/spawnflow/options/lookups/user_id');
})->throws(RuntimeException::class, 'no \'owner_id\' column');
