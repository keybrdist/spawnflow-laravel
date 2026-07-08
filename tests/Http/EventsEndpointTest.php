<?php

use Illuminate\Http\Request;
use Spawnflow\Events\SubjectChanged;
use Spawnflow\Flow;
use Spawnflow\Support\SubjectVersion;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

function eventsUser(): User
{
    return User::create([
        'name' => 'Events User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ]);
}

beforeEach(function (): void {
    config()->set('spawnflow.events', true);
    // Bounded stream for tests: two version checks, no sleeps between.
    config()->set('spawnflow.events_max_polls', 2);
    config()->set('spawnflow.events_poll_interval', 0);
});

test('flow writes bump the subject version and dispatch the typed event', function (): void {
    Event::fake([SubjectChanged::class]);

    $user = eventsUser();
    $before = SubjectVersion::snapshot(['posts'])['posts'];

    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $user);

    (new Flow)
        ->spawn($request)
        ->auth()
        ->resolve('posts')
        ->save(['title' => 'Bump', 'status' => 'draft']);

    expect(SubjectVersion::snapshot(['posts'])['posts'])->toBe($before + 1);
    Event::assertDispatched(SubjectChanged::class, fn (SubjectChanged $event) => $event->subject === 'posts' && $event->action === 'saved');
});

test('delete bumps too', function (): void {
    $user = eventsUser();
    $post = Post::create(['owner_id' => $user->id, 'title' => 'T', 'status' => 'draft']);
    $before = SubjectVersion::snapshot(['posts'])['posts'];

    $request = Request::create('/test', 'DELETE');
    $request->setUserResolver(fn () => $user);

    (new Flow)
        ->spawn($request)
        ->auth()
        ->resolve('posts')
        ->ask('DELETE', $post->id)
        ->delete($post->id);

    expect(SubjectVersion::snapshot(['posts'])['posts'])->toBe($before + 1);
});

test('the stream pushes change events for bumped subjects', function (): void {
    if (! class_exists(Illuminate\Http\StreamedEvent::class)) {
        $this->markTestSkipped('SSE channel requires Laravel 12 (StreamedEvent).');
    }

    $this->actingAs(eventsUser());

    // Route registration happens at boot; re-register with events on.
    Route::middleware([])->prefix('spawnflow')->group(function (): void {
        Route::get('/events', [Spawnflow\Http\EventsController::class, 'show']);
    });

    SubjectVersion::bump('posts');
    $version = SubjectVersion::snapshot(['posts'])['posts'];

    // Resume semantics: the client's last seen version is older than the
    // current one, so the change replays immediately on the new stream.
    $streamed = $this->get('/spawnflow/events?subjects=posts&since[posts]='.($version - 1));

    $streamed->assertOk();
    expect($streamed->headers->get('Content-Type'))->toContain('text/event-stream')
        ->and($streamed->streamedContent())
        ->toContain('event: change')
        ->toContain('"subject":"posts"')
        ->toContain('"version":'.$version);
});

test('unknown subjects are filtered out of the stream subscription', function (): void {
    if (! class_exists(Illuminate\Http\StreamedEvent::class)) {
        $this->markTestSkipped('SSE channel requires Laravel 12 (StreamedEvent).');
    }

    $this->actingAs(eventsUser());

    Route::middleware([])->prefix('spawnflow')->group(function (): void {
        Route::get('/events', [Spawnflow\Http\EventsController::class, 'show']);
    });

    // Requesting only an unknown subject yields an empty subscription —
    // the stream still responds (and ends after max_polls).
    $this->get('/spawnflow/events?subjects=nope')->assertOk();
});
