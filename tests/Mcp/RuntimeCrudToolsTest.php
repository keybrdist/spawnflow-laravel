<?php

use Spawnflow\Mcp\SpawnflowServer;
use Spawnflow\Mcp\Tools\CreateRecord;
use Spawnflow\Mcp\Tools\DeleteRecord;
use Spawnflow\Mcp\Tools\GetRecord;
use Spawnflow\Mcp\Tools\ListRecords;
use Spawnflow\Mcp\Tools\UpdateRecord;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

function crudUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'CRUD User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ], $attrs));
}

// The existing GenericController assertions, driven through MCP: same
// enforcement, different transport.

// ---------------------------------------------------------------
// create
// ---------------------------------------------------------------

test('create runs the full chain and returns the persisted record', function (): void {
    $user = crudUser();

    SpawnflowServer::actingAs($user)
        ->tool(CreateRecord::class, [
            'subject' => 'articles',
            'payload' => ['title' => 'Via MCP', 'status' => 'draft'],
        ])
        ->assertOk()
        ->assertSee('"title":"Via MCP"');

    $post = Post::query()->latest('id')->first();
    expect($post->title)->toBe('Via MCP')
        ->and($post->owner_id)->toBe($user->id);
});

test('create surfaces validation failures as per-field errors', function (): void {
    SpawnflowServer::actingAs(crudUser())
        ->tool(CreateRecord::class, ['subject' => 'articles', 'payload' => ['status' => 'draft']])
        ->assertOk()
        ->assertSee('"created":false')
        ->assertSee('"title"');

    expect(Post::count())->toBe(0);
});

// ---------------------------------------------------------------
// update — ownership + discard + echo-honesty
// ---------------------------------------------------------------

test('update is ownership-verified: a non-owner reads not-found', function (): void {
    $owner = crudUser();
    $stranger = crudUser();
    $post = Post::create(['title' => 'Mine', 'status' => 'draft', 'owner_id' => $owner->id]);

    SpawnflowServer::actingAs($stranger)
        ->tool(UpdateRecord::class, [
            'subject' => 'posts',
            'id' => $post->id,
            'payload' => ['title' => 'Stolen'],
        ])
        ->assertHasErrors(["Record not found: posts/{$post->id}"]);

    expect($post->refresh()->title)->toBe('Mine');
});

test('update discards rule-ineligible values and returns the persisted record', function (): void {
    $owner = crudUser();
    // Published: body is rule-ineligible (enabledWhen status == draft).
    $post = Post::create(['title' => 'Live', 'status' => 'published', 'owner_id' => $owner->id, 'body' => null]);

    SpawnflowServer::actingAs($owner)
        ->tool(UpdateRecord::class, [
            'subject' => 'posts',
            'id' => $post->id,
            'payload' => ['title' => 'Live v2', 'body' => 'smuggled'],
        ])
        ->assertOk()
        ->assertSee('"title":"Live v2"');

    $post->refresh();
    expect($post->title)->toBe('Live v2')
        ->and($post->body)->toBeNull();
});

// ---------------------------------------------------------------
// get / list
// ---------------------------------------------------------------

test('get filters the response by the resolved context visibleFields', function (): void {
    $owner = crudUser();
    $post = Post::create(['title' => 'Read me', 'status' => 'draft', 'owner_id' => $owner->id]);

    SpawnflowServer::actingAs($owner)
        ->tool(GetRecord::class, ['subject' => 'posts', 'id' => $post->id])
        ->assertOk()
        ->assertSee('"title":"Read me"');
});

test('get for a non-owner reads not-found', function (): void {
    $owner = crudUser();
    $post = Post::create(['title' => 'Hidden', 'status' => 'draft', 'owner_id' => $owner->id]);

    SpawnflowServer::actingAs(crudUser())
        ->tool(GetRecord::class, ['subject' => 'posts', 'id' => $post->id])
        ->assertHasErrors(["Record not found: posts/{$post->id}"]);
});

test('list is ownership-scoped', function (): void {
    $mine = crudUser();
    Post::create(['title' => 'A', 'status' => 'draft', 'owner_id' => $mine->id]);
    Post::create(['title' => 'B', 'status' => 'draft', 'owner_id' => crudUser()->id]);

    SpawnflowServer::actingAs($mine)
        ->tool(ListRecords::class, ['subject' => 'posts'])
        ->assertOk()
        ->assertSee('"title":"A"')
        ->assertDontSee('"title":"B"');
});

// ---------------------------------------------------------------
// delete
// ---------------------------------------------------------------

test('delete removes an owned record, single id only', function (): void {
    $owner = crudUser();
    $post = Post::create(['title' => 'Bye', 'status' => 'draft', 'owner_id' => $owner->id]);

    SpawnflowServer::actingAs($owner)
        ->tool(DeleteRecord::class, ['subject' => 'posts', 'id' => $post->id])
        ->assertOk()
        ->assertSee('"deleted":true');

    expect(Post::find($post->id))->toBeNull();
});

test('delete for a non-owner reads not-found and removes nothing', function (): void {
    $owner = crudUser();
    $post = Post::create(['title' => 'Keep', 'status' => 'draft', 'owner_id' => $owner->id]);

    SpawnflowServer::actingAs(crudUser())
        ->tool(DeleteRecord::class, ['subject' => 'posts', 'id' => $post->id])
        ->assertHasErrors(["Record not found: posts/{$post->id}"]);

    expect(Post::find($post->id))->not->toBeNull();
});

test('list pagination follows the page argument', function (): void {
    $user = crudUser();
    foreach (range(1, 3) as $i) {
        Post::create(['title' => "P{$i}", 'status' => 'draft', 'owner_id' => $user->id]);
    }

    SpawnflowServer::actingAs($user)
        ->tool(ListRecords::class, ['subject' => 'posts', 'per_page' => 2, 'page' => 2])
        ->assertOk()
        ->assertSee('"current_page":2')
        ->assertSee('"title":"P1"'); // default sort id desc → page 2 holds the oldest
});
