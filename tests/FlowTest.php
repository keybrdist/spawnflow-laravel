<?php

use Illuminate\Http\Request;
use Spawnflow\Exceptions\OwnershipException;
use Spawnflow\Exceptions\UnauthenticatedException;
use Spawnflow\Exceptions\UnresolvableSubjectException;
use Spawnflow\Flow;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\PostContext;
use Spawnflow\Tests\Fixtures\User;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

function createUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'roles' => '',
    ], $attrs));
}

function createPost(User $owner, array $attrs = []): Post
{
    return Post::create(array_merge([
        'owner_id' => $owner->id,
        'title' => 'Test Post',
        'body' => 'Some body text',
        'status' => 'draft',
    ], $attrs));
}

function authedRequest(User $user): Request
{
    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $user);

    return $request;
}

function guestRequest(): Request
{
    $request = Request::create('/test', 'GET');
    $request->setUserResolver(fn () => null);

    return $request;
}

// ---------------------------------------------------------------
// spawn() + auth()
// ---------------------------------------------------------------

test('spawn extracts user from request', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    $flow = (new Flow)->spawn($request);

    expect($flow->getUser()->id)->toBe($user->id);
});

test('auth passes for authenticated user', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    $flow = (new Flow)->spawn($request)->auth();

    expect($flow->getUser()->id)->toBe($user->id);
});

test('auth throws for unauthenticated request', function (): void {
    $request = guestRequest();

    (new Flow)->spawn($request)->auth();
})->throws(UnauthenticatedException::class);

test('auth accepts a required role when present', function (): void {
    $user = createUser(['roles' => 'editor,viewer']);
    $request = authedRequest($user);

    $flow = (new Flow)->spawn($request)->auth('editor');
    expect($flow->getUser()->id)->toBe($user->id);
});

test('auth throws when required role is missing', function (): void {
    $user = createUser(['roles' => 'editor,viewer']);
    $request = authedRequest($user);

    (new Flow)->spawn($request)->auth('admin');
})->throws(UnauthenticatedException::class);

// ---------------------------------------------------------------
// resolve()
// ---------------------------------------------------------------

test('resolve maps alias to model instance', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    $flow = (new Flow)->spawn($request)->auth()->resolve('posts');

    expect($flow->getSubject())->toBeInstanceOf(Post::class);
});

test('resolve throws for unknown alias', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    (new Flow)->spawn($request)->auth()->resolve('nonexistent');
})->throws(UnresolvableSubjectException::class);

test('resolve is case-insensitive', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    $flow = (new Flow)->spawn($request)->auth()->resolve('Posts');

    expect($flow->getSubject())->toBeInstanceOf(Post::class);
});

// ---------------------------------------------------------------
// ask() — ownership
// ---------------------------------------------------------------

test('ask passes for owned record', function (): void {
    $user = createUser();
    $post = createPost($user);
    $request = authedRequest($user);

    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('GET', $post->id);

    expect($flow->getInstance()->id)->toBe($post->id);
});

test('ask throws for unowned record', function (): void {
    $owner = createUser();
    $other = createUser(['email' => 'other@example.com']);
    $post = createPost($owner);
    $request = authedRequest($other);

    (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('GET', $post->id);
})->throws(OwnershipException::class);

test('ask validates ownership for array of ids', function (): void {
    $user = createUser();
    $post1 = createPost($user, ['title' => 'Post 1']);
    $post2 = createPost($user, ['title' => 'Post 2']);
    $request = authedRequest($user);

    // All owned — should pass
    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('DELETE', [$post1->id, $post2->id]);

    expect($flow)->toBeInstanceOf(Flow::class);
});

test('ask throws when not all ids are owned', function (): void {
    $user = createUser();
    $other = createUser(['email' => 'other@example.com']);
    $ownedPost = createPost($user);
    $otherPost = createPost($other);
    $request = authedRequest($user);

    (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('DELETE', [$ownedPost->id, $otherPost->id]);
})->throws(OwnershipException::class);

// ---------------------------------------------------------------
// fields() — field-level permissions
// ---------------------------------------------------------------

test('fields resolves context from user and record state', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'draft']);
    $request = authedRequest($user);

    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields(PostContext::class);

    expect($flow->getContext())->toBe(PostContext::OwnerDraft);
});

test('fields resolves published context for published posts', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'published']);
    $request = authedRequest($user);

    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields(PostContext::class);

    expect($flow->getContext())->toBe(PostContext::OwnerPublished);
});

test('viewer context has no editable fields', function (): void {
    $owner = createUser();
    $viewer = createUser(['email' => 'viewer@example.com']);
    $post = createPost($owner);

    // Manually set up a flow to test viewer context resolution
    // We need the record loaded, so we use the owner to ask(), then swap context
    // In practice, viewers would hit a different path, but we test the context enum directly
    expect(PostContext::Viewer->editableFields())->toBe([]);
});

test('fields auto-resolves context from config', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'draft']);
    $request = authedRequest($user);

    // fields() without explicit class should use config('spawnflow.contexts.posts')
    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields();

    expect($flow->getContext())->toBe(PostContext::OwnerDraft);
});

// ---------------------------------------------------------------
// save()
// ---------------------------------------------------------------

test('save creates a new record with ownership', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->save(['title' => 'New Post', 'body' => 'Content', 'status' => 'draft']);

    expect($flow->getInstance())
        ->title->toBe('New Post')
        ->body->toBe('Content')
        ->owner_id->toBe($user->id);

    expect(Post::count())->toBe(1);
});

test('save updates an existing record', function (): void {
    $user = createUser();
    $post = createPost($user, ['title' => 'Original']);
    $request = authedRequest($user);

    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->save(['title' => 'Updated']);

    expect($flow->getInstance()->title)->toBe('Updated');
    expect($post->fresh()->title)->toBe('Updated');
});

test('save strips disallowed fields when context is active', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'published', 'title' => 'Original']);
    $request = authedRequest($user);

    // OwnerPublished context only allows 'title'
    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields(PostContext::class)
        ->save(['title' => 'Updated', 'body' => 'Hacked body', 'status' => 'draft']);

    $fresh = $post->fresh();
    expect($fresh->title)->toBe('Updated');        // allowed
    expect($fresh->body)->toBe('Some body text');   // stripped — kept original
    expect($fresh->status)->toBe('published');      // stripped — kept original
});

// ---------------------------------------------------------------
// delete()
// ---------------------------------------------------------------

test('delete removes a record', function (): void {
    $user = createUser();
    $post = createPost($user);
    $request = authedRequest($user);

    $response = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('DELETE', $post->id)
        ->delete($post->id);

    expect($response->getStatusCode())->toBe(200);
    expect(Post::find($post->id))->toBeNull();
});

test('delete removes multiple records', function (): void {
    $user = createUser();
    $post1 = createPost($user, ['title' => 'P1']);
    $post2 = createPost($user, ['title' => 'P2']);
    $request = authedRequest($user);

    (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('DELETE', [$post1->id, $post2->id])
        ->delete([$post1->id, $post2->id]);

    expect(Post::count())->toBe(0);
});

// ---------------------------------------------------------------
// gate() + after()
// ---------------------------------------------------------------

test('gate passes when callback does not throw', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'draft']);
    $request = authedRequest($user);

    $flow = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->gate(fn ($f) => $f->getInstance()->status === 'draft'
            || throw new \Spawnflow\Exceptions\StateException('Must be draft'));

    expect($flow)->toBeInstanceOf(Flow::class);
});

test('gate throws when condition fails', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'published']);
    $request = authedRequest($user);

    (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->gate(fn ($f) => $f->getInstance()->status === 'draft'
            || throw new \Spawnflow\Exceptions\StateException('Must be draft'));
})->throws(\Spawnflow\Exceptions\StateException::class);

test('after executes callback with flow context', function (): void {
    $user = createUser();
    $request = authedRequest($user);
    $sideEffect = null;

    (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->save(['title' => 'New Post', 'status' => 'draft'])
        ->after(function ($f) use (&$sideEffect): void {
            $sideEffect = 'created:'.$f->getInstance()->title;
        });

    expect($sideEffect)->toBe('created:New Post');
});

// ---------------------------------------------------------------
// present()
// ---------------------------------------------------------------

test('present returns json response of the instance', function (): void {
    $user = createUser();
    $post = createPost($user);
    $request = authedRequest($user);

    $response = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('GET', $post->id)
        ->present();

    expect($response->getStatusCode())->toBe(200);

    $data = $response->getData(true);
    expect($data['title'])->toBe('Test Post');
    expect($data['id'])->toBe($post->id);
});

test('present filters to visible fields when context is active', function (): void {
    $owner = createUser();
    $post = createPost($owner, ['status' => 'draft']);
    $request = authedRequest($owner);

    $response = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('GET', $post->id)
        ->fields(PostContext::class)
        ->present();

    $data = $response->getData(true);

    // OwnerDraft visible: id, title, body, status, owner_id, created_at, updated_at
    expect($data)->toHaveKeys(['id', 'title', 'body', 'status', 'owner_id']);
});

test('present accepts custom status code', function (): void {
    $user = createUser();
    $request = authedRequest($user);

    $response = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->save(['title' => 'Created', 'status' => 'draft'])
        ->present(statusCode: 201);

    expect($response->getStatusCode())->toBe(201);
});

// ---------------------------------------------------------------
// Full chain integration
// ---------------------------------------------------------------

test('full create chain works end to end', function (): void {
    $user = createUser();
    $request = Request::create('/test', 'POST', [
        'title' => 'My Post',
        'body' => 'Content here',
        'status' => 'draft',
    ]);
    $request->setUserResolver(fn () => $user);

    $response = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->fields(PostContext::class)
        ->validate()
        ->save($request->all())
        ->present(statusCode: 201);

    expect($response->getStatusCode())->toBe(201);
    $data = $response->getData(true);
    expect($data['title'])->toBe('My Post');
    expect($data['owner_id'])->toBe($user->id);
});

test('full update chain works end to end', function (): void {
    $user = createUser();
    $post = createPost($user, ['status' => 'draft']);
    $request = Request::create('/test', 'POST', [
        'title' => 'Updated Title',
        'status' => 'published',
    ]);
    $request->setUserResolver(fn () => $user);

    $response = (new Flow)
        ->spawn($request)->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields(PostContext::class)
        ->validate()
        ->save($request->all())
        ->present();

    expect($response->getStatusCode())->toBe(200);
    $fresh = $post->fresh();
    expect($fresh->title)->toBe('Updated Title');
    expect($fresh->status)->toBe('published');
});
