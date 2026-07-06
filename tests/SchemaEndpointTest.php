<?php

use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

function schemaUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'Schema User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ], $attrs));
}

// ---------------------------------------------------------------
// All-variants schema: GET /spawnflow/schema/{subject}
// ---------------------------------------------------------------

test('variants schema returns versioned contract with descriptors and variants', function (): void {
    $this->actingAs(schemaUser());

    $response = $this->getJson('/spawnflow/schema/posts');

    $response->assertOk()
        ->assertJsonPath('spawnflow', '1')
        ->assertJsonPath('resource', 'posts')
        ->assertJsonPath('fields.title.type', 'string')
        ->assertJsonPath('fields.title.widget', 'input')
        ->assertJsonPath('fields.status.type', 'enum')
        ->assertJsonPath('fields.status.options', [
            ['value' => 'draft', 'label' => 'Draft'],
            ['value' => 'published', 'label' => 'Live'],
        ])
        ->assertJsonCount(3, 'variants');
});

test('variants carry effective structured rules per editable field', function (): void {
    $this->actingAs(schemaUser());

    $variants = collect($this->getJson('/spawnflow/schema/posts')->json('variants'));
    $ownerDraft = $variants->firstWhere('context', 'owner:draft');

    expect($ownerDraft['editable_fields'])->toBe(['title', 'body', 'status'])
        ->and($ownerDraft['rules']['title'])->toContainEqual(['rule' => 'max', 'params' => [255]])
        ->and($ownerDraft['rules']['status'])->toContainEqual(['rule' => 'in', 'params' => ['draft', 'published']]);

    $ownerPublished = $variants->firstWhere('context', 'owner:published');
    expect(array_keys($ownerPublished['rules']))->toBe(['title']);

    $viewer = $variants->firstWhere('context', 'viewer');
    expect($viewer['editable_fields'])->toBe([])
        ->and($viewer['rules'])->toBe([]);
});

// ---------------------------------------------------------------
// Resolved schema: GET /spawnflow/schema/{subject}/{id}
// ---------------------------------------------------------------

test('resolved schema returns the caller-specific variant with field flags', function (): void {
    $user = schemaUser();
    $post = Post::create([
        'owner_id' => $user->id,
        'title' => 'Draft post',
        'status' => 'draft',
    ]);

    $this->actingAs($user);

    $response = $this->getJson("/spawnflow/schema/posts/{$post->id}");

    $response->assertOk()
        ->assertJsonPath('spawnflow', '1')
        ->assertJsonPath('context', 'owner:draft')
        ->assertJsonPath('fields.title.editable', true)
        ->assertJsonPath('fields.title.visible', true)
        ->assertJsonPath('fields.owner_id.editable', false)
        ->assertJsonPath('fields.owner_id.visible', true);

    $rules = $response->json('fields.body.rules');
    expect($rules)->toContainEqual(['rule' => 'nullable'])
        ->and($response->json('fields.status.options'))->toHaveCount(2)
        ->and($response->json('fields.owner_id'))->not->toHaveKey('rules');
});

test('resolved schema resolves the viewer variant for non-owners', function (): void {
    $owner = schemaUser();
    $post = Post::create([
        'owner_id' => $owner->id,
        'title' => 'Not yours',
        'status' => 'published',
    ]);

    $this->actingAs(schemaUser());

    $response = $this->getJson("/spawnflow/schema/posts/{$post->id}");

    $response->assertOk()
        ->assertJsonPath('context', 'viewer')
        ->assertJsonPath('fields.title.editable', false)
        ->assertJsonPath('fields.title.visible', true)
        ->assertJsonMissingPath('fields.body');
});

test('resolved schema returns 404 for a missing record', function (): void {
    $this->actingAs(schemaUser());

    $this->getJson('/spawnflow/schema/posts/999999')->assertNotFound();
});

test('resolved schema requires authentication', function (): void {
    $user = schemaUser();
    $post = Post::create([
        'owner_id' => $user->id,
        'title' => 'Post',
        'status' => 'draft',
    ]);

    $this->getJson("/spawnflow/schema/posts/{$post->id}")->assertStatus(401);
});

// ---------------------------------------------------------------
// Edge cases
// ---------------------------------------------------------------

test('unknown subject returns 404', function (): void {
    $this->actingAs(schemaUser());

    $this->getJson('/spawnflow/schema/unknown')->assertNotFound();
});

test('subject without a context returns the default schema', function (): void {
    config()->set('spawnflow.contexts', []);
    $registry = new \Spawnflow\ConfigSubjectRegistry;
    $serializer = new \Spawnflow\Schema\SchemaSerializer($registry);

    $schema = $serializer->defaultSchema('posts');

    expect($schema['spawnflow'])->toBe('1')
        ->and($schema['context'])->toBe('default')
        ->and($schema['fields']['title']['editable'])->toBeTrue()
        ->and($schema['fields']['title']['visible'])->toBeTrue()
        ->and($schema['fields']['title']['rules'])->toContainEqual(['rule' => 'required']);
});
