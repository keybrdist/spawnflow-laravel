<?php

use Illuminate\Http\Request;
use Spawnflow\ConfigSubjectRegistry;
use Spawnflow\Exceptions\InvalidEligibilityException;
use Spawnflow\Flow;
use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;
use Spawnflow\Schema\SchemaSerializer;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

function eligUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'Elig User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ], $attrs));
}

function eligRequest(User $user, array $payload = []): Request
{
    $request = Request::create('/test', 'POST', $payload);
    $request->setUserResolver(fn () => $user);

    return $request;
}

// ---------------------------------------------------------------
// Wire shape — eligibility envelopes and resolved verdicts
// ---------------------------------------------------------------

test('variants schema carries eligibility envelopes and create-time resolved verdicts', function (): void {
    $this->actingAs(eligUser());

    $response = $this->getJson('/spawnflow/schema/posts');

    $response->assertOk()
        ->assertJsonPath('fields.body.eligibility', [
            ['effect' => 'enable', 'condition' => ['==' => [['var' => 'status'], 'draft']]],
        ])
        // No status default -> the enable condition fails at create time.
        ->assertJsonPath('resolved.body', ['visible' => true, 'enabled' => false]);
});

test('resolved schema evaluates rule verdicts against the record', function (): void {
    $user = eligUser();
    $this->actingAs($user);

    $draft = Post::create(['owner_id' => $user->id, 'title' => 'Draft', 'status' => 'draft']);
    $published = Post::create(['owner_id' => $user->id, 'title' => 'Live', 'status' => 'published']);

    $this->getJson("/spawnflow/schema/posts/{$draft->id}")
        ->assertOk()
        ->assertJsonPath('resolved.body', ['visible' => true, 'enabled' => true]);

    $this->getJson("/spawnflow/schema/posts/{$published->id}")
        ->assertOk()
        ->assertJsonPath('resolved.body', ['visible' => true, 'enabled' => false]);
});

// ---------------------------------------------------------------
// Write path — rules are enforced, never cosmetic
// ---------------------------------------------------------------

test('save discards a field made ineligible by the submitted state', function (): void {
    $user = eligUser();
    $post = Post::create([
        'owner_id' => $user->id, 'title' => 'T', 'body' => 'Original', 'status' => 'draft',
    ]);

    // Publishing and writing body in one request: the merged state is
    // published, so body is rule-disabled and its value is discarded.
    (new Flow)
        ->spawn(eligRequest($user))
        ->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields()
        ->save(['status' => 'published', 'body' => 'Smuggled']);

    $post->refresh();
    expect($post->status)->toBe('published')
        ->and($post->body)->toBe('Original');
});

test('save keeps the field while the rule passes', function (): void {
    $user = eligUser();
    $post = Post::create([
        'owner_id' => $user->id, 'title' => 'T', 'body' => 'Original', 'status' => 'draft',
    ]);

    (new Flow)
        ->spawn(eligRequest($user))
        ->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields()
        ->save(['body' => 'Updated']);

    expect($post->refresh()->body)->toBe('Updated');
});

test('create discards rule-ineligible fields against defaults plus input', function (): void {
    $user = eligUser();

    // No fields() on purpose: rule enforcement is independent of the
    // context-variant axis and applies even without one.
    (new Flow)
        ->spawn(eligRequest($user))
        ->auth()
        ->resolve('posts')
        ->save(['title' => 'New', 'status' => 'published', 'body' => 'Dropped']);

    $post = Post::query()->latest('id')->first();
    expect($post->body)->toBeNull()
        ->and($post->status)->toBe('published');
});

test('validate skips rules of rule-ineligible fields', function (): void {
    $user = eligUser();
    $post = Post::create([
        'owner_id' => $user->id, 'title' => 'T', 'body' => 'Original', 'status' => 'draft',
    ]);

    // body's string rule would reject an array — but body is ineligible
    // in the submitted (published) state, so its rules are skipped and
    // save() discards the value.
    (new Flow)
        ->spawn(eligRequest($user, $payload = ['title' => 'T', 'status' => 'published', 'body' => ['not', 'a', 'string']]))
        ->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields()
        ->validate()
        ->save($payload);

    expect($post->refresh()->body)->toBe('Original');
});

test('validate still enforces rules while the field is eligible', function (): void {
    $user = eligUser();
    $post = Post::create([
        'owner_id' => $user->id, 'title' => 'T', 'body' => 'Original', 'status' => 'draft',
    ]);

    (new Flow)
        ->spawn(eligRequest($user, $payload = ['title' => 'T', 'body' => ['not', 'a', 'string']]))
        ->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->fields()
        ->validate()
        ->save($payload);
})->throws(Illuminate\Validation\ValidationException::class);

// ---------------------------------------------------------------
// Declaration-time guard and serverResolved escape hatch
// ---------------------------------------------------------------

test('serialization throws when a rule references an undeclared field', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [
                Field::string('title')->visibleWhen(['==' => [['var' => 'no_such_field'], 1]]),
            ];
        }
    };

    config()->set('spawnflow.fields.articles', $fieldSet::class);

    (new SchemaSerializer(new ConfigSubjectRegistry))->defaultSchema('articles');
})->throws(InvalidEligibilityException::class, 'undeclared field');

test('serialization throws when a rule references a field the variant cannot see', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [
                Field::string('title'),
                Field::text('body'),
                // The Viewer variant exposes status but cannot see body —
                // a client on that variant could never re-evaluate this.
                Field::string('status')
                    ->visibleWhen(['==' => [['var' => 'body'], 'secret']]),
            ];
        }
    };

    config()->set('spawnflow.fields.posts', $fieldSet::class);

    $registry = new ConfigSubjectRegistry;
    (new SchemaSerializer($registry))->variants('posts', $registry->contextFor('posts'));
})->throws(InvalidEligibilityException::class, 'cannot see');

test('serverResolved ships the verdict without the condition and bypasses the guard', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [
                Field::string('title'),
                Field::text('body'),
                Field::string('status')
                    ->visibleWhen(['==' => [['var' => 'body'], 'secret']])
                    ->serverResolved(),
            ];
        }
    };

    config()->set('spawnflow.fields.posts', $fieldSet::class);

    $registry = new ConfigSubjectRegistry;
    $schema = (new SchemaSerializer($registry))->variants('posts', $registry->contextFor('posts'));

    expect($schema['fields']['status'])->toHaveKey('serverResolved', true)
        ->not->toHaveKey('eligibility')
        ->and($schema['resolved']['status'])->toBe(['visible' => false, 'enabled' => true]);
});
