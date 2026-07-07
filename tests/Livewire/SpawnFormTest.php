<?php

use Livewire\Livewire;
use Spawnflow\Livewire\SpawnForm;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

function livewireUser(): User
{
    return User::create([
        'name' => 'Livewire User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ]);
}

test('renders the resolved variant for the record owner', function (): void {
    $user = livewireUser();
    $post = Post::create(['owner_id' => $user->id, 'title' => 'Draft post', 'status' => 'draft']);

    Livewire::actingAs($user)
        ->test(SpawnForm::class, ['subject' => 'posts', 'recordId' => $post->id])
        ->assertSee('Title')
        ->assertSee('Body')
        ->assertSet('values.title', 'Draft post');
});

test('updates a record through the same Flow write path', function (): void {
    $user = livewireUser();
    $post = Post::create(['owner_id' => $user->id, 'title' => 'Before', 'status' => 'draft']);

    Livewire::actingAs($user)
        ->test(SpawnForm::class, ['subject' => 'posts', 'recordId' => $post->id])
        ->set('values.title', 'After')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('success', 'Saved.');

    expect($post->refresh()->title)->toBe('After');
});

test('validation errors surface on the component', function (): void {
    $user = livewireUser();
    $post = Post::create(['owner_id' => $user->id, 'title' => 'Before', 'status' => 'draft']);

    Livewire::actingAs($user)
        ->test(SpawnForm::class, ['subject' => 'posts', 'recordId' => $post->id])
        ->set('values.title', '')
        ->call('save')
        ->assertHasErrors('title');

    expect($post->refresh()->title)->toBe('Before');
});

test('rule-ineligible values are discarded by the shared write path', function (): void {
    $user = livewireUser();
    $post = Post::create(['owner_id' => $user->id, 'title' => 'T', 'body' => 'Original', 'status' => 'draft']);

    // Publishing and editing body together: body is rule-disabled in the
    // submitted state (enabledWhen status == draft) and gets discarded.
    Livewire::actingAs($user)
        ->test(SpawnForm::class, ['subject' => 'posts', 'recordId' => $post->id])
        ->set('values.status', 'published')
        ->set('values.body', 'Smuggled')
        ->call('save')
        ->assertHasNoErrors();

    $post->refresh();
    expect($post->status)->toBe('published')
        ->and($post->body)->toBe('Original');
});

test('creates a record when no recordId is given', function (): void {
    $user = livewireUser();

    Livewire::actingAs($user)
        ->test(SpawnForm::class, ['subject' => 'posts'])
        ->set('values.title', 'Fresh')
        ->set('values.status', 'draft')
        ->call('save')
        ->assertHasNoErrors();

    $post = Post::query()->latest('id')->first();
    expect($post->title)->toBe('Fresh')
        ->and($post->owner_id)->toBe($user->id);
});
