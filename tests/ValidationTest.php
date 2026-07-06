<?php

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Flow;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\PostContext;
use Spawnflow\Tests\Fixtures\UpdatePostRequest;
use Spawnflow\Tests\Fixtures\User;
use Spawnflow\Validation\RuleResolver;

function validationUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'Validation User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ], $attrs));
}

function requestFor(User $user, array $data = [], array $headers = []): Request
{
    $request = Request::create('/test', 'POST', $data);
    $request->setUserResolver(fn () => $user);
    foreach ($headers as $key => $value) {
        $request->headers->set($key, $value);
    }

    return $request;
}

// ---------------------------------------------------------------
// RuleResolver
// ---------------------------------------------------------------

test('resolver composes fieldset rules with descriptor-implied rules when no context', function (): void {
    $rules = app(RuleResolver::class)->for('articles', null);

    expect($rules['title'])->toBe(['required', 'string', 'max:100'])
        ->and($rules['post_id'])->toBe(['exists:posts,id']);
});

test('resolver scopes to editable fields and lets context overrides win', function (): void {
    $resolver = app(RuleResolver::class);

    $draft = $resolver->for('posts', PostContext::OwnerDraft);
    expect(array_keys($draft))->toBe(['title', 'body', 'status'])
        ->and($draft['title'])->toBe(['required', 'string', 'max:255'])
        // Context override 'in:draft,published' wins; no duplicate implied in-rule.
        ->and($draft['status'])->toBe(['in:draft,published']);

    $published = $resolver->for('posts', PostContext::OwnerPublished);
    expect(array_keys($published))->toBe(['title']);
});

test('resolver falls back to context validation for subjects without a fieldset', function (): void {
    config()->set('spawnflow.fields', []);
    $resolver = new RuleResolver(new \Spawnflow\ConfigSubjectRegistry);

    expect($resolver->for('posts', PostContext::OwnerPublished))
        ->toBe(['title' => 'required|string|max:255']);
});

// ---------------------------------------------------------------
// Flow::validate() sourcing
// ---------------------------------------------------------------

test('validate falls back to fieldset rules when subject has no context', function (): void {
    $user = validationUser();

    $flow = fn (array $data) => (new Flow)
        ->spawn(requestFor($user, $data))->auth()
        ->resolve('articles')
        ->validate();

    expect(fn () => $flow(['body' => 'no title']))->toThrow(ValidationException::class);

    $flow(['title' => 'Valid title']);
    expect(true)->toBeTrue();
});

test('validate enforces implied relation existence', function (): void {
    $user = validationUser();

    $chain = fn (array $data) => (new Flow)
        ->spawn(requestFor($user, $data))->auth()
        ->resolve('articles')
        ->validate();

    expect(fn () => $chain(['title' => 'ok', 'post_id' => 999999]))
        ->toThrow(ValidationException::class);

    $post = Post::create(['owner_id' => $user->id, 'title' => 'Target', 'status' => 'draft']);
    $chain(['title' => 'ok', 'post_id' => $post->id]);
    expect(true)->toBeTrue();
});

test('explicit rules argument still overrides all sources', function (): void {
    $user = validationUser();

    (new Flow)
        ->spawn(requestFor($user, ['anything' => 'goes']))->auth()
        ->resolve('articles')
        ->validate(['anything' => 'required|string']);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------
// Precognition
// ---------------------------------------------------------------

test('precognitive request validates then halts the chain with 204', function (): void {
    $user = validationUser();
    $request = requestFor($user, ['title' => 'Valid'], ['Precognition' => 'true']);

    try {
        (new Flow)->spawn($request)->auth()->resolve('articles')->validate();
        $this->fail('Expected HttpResponseException');
    } catch (HttpResponseException $e) {
        $response = $e->getResponse();
        expect($response->getStatusCode())->toBe(204)
            ->and($response->headers->get('Precognition'))->toBe('true')
            ->and($response->headers->get('Precognition-Success'))->toBe('true');
    }
});

test('precognitive request surfaces validation errors as 422', function (): void {
    $user = validationUser();
    $request = requestFor($user, [], ['Precognition' => 'true']);

    expect(fn () => (new Flow)->spawn($request)->auth()->resolve('articles')->validate())
        ->toThrow(ValidationException::class);
});

test('precognition validate-only scopes validation to the named fields', function (): void {
    $user = validationUser();

    // post_id invalid, but only title is being validated — passes and halts.
    $request = requestFor($user, ['title' => 'Valid', 'post_id' => 999999], [
        'Precognition' => 'true',
        'Precognition-Validate-Only' => 'title',
    ]);

    expect(fn () => (new Flow)->spawn($request)->auth()->resolve('articles')->validate())
        ->toThrow(HttpResponseException::class);
});

// ---------------------------------------------------------------
// FormRequest bridge
// ---------------------------------------------------------------

test('form request bridge validates with context-resolved rules', function (): void {
    $user = validationUser();
    $post = Post::create(['owner_id' => $user->id, 'title' => 'Original', 'status' => 'draft']);

    Route::post('/bridge/posts/{id}', fn (UpdatePostRequest $request) => response()->json($request->validated()));

    $this->actingAs($user);

    // owner:draft — title required
    $this->postJson("/bridge/posts/{$post->id}", ['body' => 'no title'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title']);

    $this->postJson("/bridge/posts/{$post->id}", ['title' => 'Updated', 'status' => 'published'])
        ->assertOk()
        ->assertJsonPath('title', 'Updated');
});

test('form request bridge resolves create context from a synthetic record', function (): void {
    $user = validationUser();

    Route::post('/bridge/posts', fn (UpdatePostRequest $request) => response()->json($request->validated()));

    $this->actingAs($user);

    // Synthetic record with status=draft resolves owner:draft — body rule active.
    $draft = $this->postJson('/bridge/posts', ['title' => 'New', 'body' => 'B', 'status' => 'draft'])
        ->assertOk();
    expect($draft->json())->toHaveKey('body');

    // status=published resolves owner:published — only title is validated.
    $published = $this->postJson('/bridge/posts', ['title' => 'New', 'body' => 'B', 'status' => 'published'])
        ->assertOk();
    expect($published->json())->not->toHaveKey('body');
});

test('validate accepts explicit data for wrapped or transformed payloads', function (): void {
    $user = validationUser();

    $chain = fn (array $payload) => (new Flow)
        ->spawn(requestFor($user, ['payload' => $payload]))->auth()
        ->resolve('articles')
        ->validate(data: $payload);

    expect(fn () => $chain([])) ->toThrow(ValidationException::class);

    $chain(['title' => 'From wrapped payload']);
    expect(true)->toBeTrue();
});
