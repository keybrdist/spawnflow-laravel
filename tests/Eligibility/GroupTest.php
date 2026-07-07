<?php

use Illuminate\Http\Request;
use Spawnflow\ConfigSubjectRegistry;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Exceptions\InvalidEligibilityException;
use Spawnflow\Flow;
use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;
use Spawnflow\Schema\Group;
use Spawnflow\Schema\SchemaSerializer;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\User;

class BusinessBriefFields extends FieldSet
{
    public static function fields(): array
    {
        return [
            Field::string('title'),
            Field::enum('status', Spawnflow\Tests\Fixtures\PostStatus::class),
            Field::text('body'),
            // Own SHOW rule that passes — the hidden group must still win.
            Field::string('owner_id')->visibleWhen(true),
        ];
    }

    public static function groups(): array
    {
        return [
            Group::make('meta', ['body', 'owner_id'])
                ->label('Meta')
                ->visibleWhen(['==' => [['var' => 'status'], 'draft']]),
        ];
    }
}

function groupUser(): User
{
    return User::create([
        'name' => 'Group User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ]);
}

test('group verdicts AND into member field verdicts — hidden group overrides a passing child SHOW', function (): void {
    $draft = Eligibility::fieldVerdicts(BusinessBriefFields::class, ['status' => 'draft']);
    $published = Eligibility::fieldVerdicts(BusinessBriefFields::class, ['status' => 'published']);

    expect($draft['owner_id'])->toBe(['visible' => true, 'enabled' => true])
        ->and($published['owner_id'])->toBe(['visible' => false, 'enabled' => true])
        ->and($published['body'])->toBe(['visible' => false, 'enabled' => true])
        // Non-members are untouched by the group.
        ->and($published)->not->toHaveKey('title');
});

test('groups and their resolved verdicts are on the wire', function (): void {
    config()->set('spawnflow.fields.articles', BusinessBriefFields::class);

    $schema = (new SchemaSerializer(new ConfigSubjectRegistry))->defaultSchema('articles');

    expect($schema['groups'])->toBe([[
        'name' => 'meta',
        'label' => 'Meta',
        'fields' => ['body', 'owner_id'],
        'eligibility' => [
            ['effect' => 'show', 'condition' => ['==' => [['var' => 'status'], 'draft']]],
        ],
    ]])
        // Create-time verdicts: no status default -> group hidden.
        ->and($schema['resolved_groups']['meta'])->toBe(['visible' => false, 'enabled' => true])
        ->and($schema['resolved']['body'])->toBe(['visible' => false, 'enabled' => true]);
});

test('save discards writes to members of an ineligible group', function (): void {
    config()->set('spawnflow.fields.posts', BusinessBriefFields::class);

    $user = groupUser();
    $post = Post::create([
        'owner_id' => $user->id, 'title' => 'T', 'body' => 'Original', 'status' => 'published',
    ]);

    $request = Request::create('/test', 'POST');
    $request->setUserResolver(fn () => $user);

    (new Flow)
        ->spawn($request)
        ->auth()
        ->resolve('posts')
        ->ask('POST', $post->id)
        ->save(['title' => 'Updated', 'body' => 'Through the hidden group']);

    $post->refresh();
    expect($post->title)->toBe('Updated')
        ->and($post->body)->toBe('Original');
});

test('a group naming an undeclared field throws', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [Field::string('title')];
        }

        public static function groups(): array
        {
            return [Group::make('bad', ['title', 'ghost'])];
        }
    };

    $fieldSet::allGroups();
})->throws(InvalidEligibilityException::class, 'undeclared field');

test('a field in two groups throws', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [Field::string('title'), Field::string('body')];
        }

        public static function groups(): array
        {
            return [
                Group::make('one', ['title', 'body']),
                Group::make('two', ['body']),
            ];
        }
    };

    $fieldSet::allGroups();
})->throws(InvalidEligibilityException::class, 'at most one group');

test('a group rule referencing a variant-invisible field throws for variants exposing members', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [
                Field::string('title'),
                Field::text('body'),
                Field::string('status'),
            ];
        }

        public static function groups(): array
        {
            return [
                // Viewer exposes title but cannot see body.
                Group::make('main', ['title'])
                    ->visibleWhen(['==' => [['var' => 'body'], 'x']]),
            ];
        }
    };

    config()->set('spawnflow.fields.posts', $fieldSet::class);

    $registry = new ConfigSubjectRegistry;
    (new SchemaSerializer($registry))->variants('posts', $registry->contextFor('posts'));
})->throws(InvalidEligibilityException::class, 'cannot see');

test('serverResolved group ships verdict without condition and bypasses the guard', function (): void {
    $fieldSet = new class extends FieldSet
    {
        public static function fields(): array
        {
            return [
                Field::string('title'),
                Field::text('body'),
                Field::string('status'),
            ];
        }

        public static function groups(): array
        {
            return [
                Group::make('main', ['title'])
                    ->visibleWhen(['==' => [['var' => 'body'], 'x']])
                    ->serverResolved(),
            ];
        }
    };

    config()->set('spawnflow.fields.posts', $fieldSet::class);

    $registry = new ConfigSubjectRegistry;
    $schema = (new SchemaSerializer($registry))->variants('posts', $registry->contextFor('posts'));

    expect($schema['groups'][0])->toHaveKey('serverResolved', true)
        ->not->toHaveKey('eligibility')
        ->and($schema['resolved_groups']['main'])->toBe(['visible' => false, 'enabled' => true]);
});
