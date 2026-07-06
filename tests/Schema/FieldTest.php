<?php

use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldType;
use Spawnflow\Schema\SchemaSerializer;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\PostFields;
use Spawnflow\Tests\Fixtures\PostStatus;

// ---------------------------------------------------------------
// Field construction
// ---------------------------------------------------------------

test('named constructors set type and default widget', function (): void {
    expect(Field::string('title')->type)->toBe(FieldType::String)
        ->and(Field::string('title')->getWidget())->toBe('input')
        ->and(Field::text('body')->getWidget())->toBe('textarea')
        ->and(Field::int('count')->getWidget())->toBe('number')
        ->and(Field::bool('active')->getWidget())->toBe('checkbox')
        ->and(Field::date('published_at')->getWidget())->toBe('datepicker')
        ->and(Field::enum('status', PostStatus::class)->getWidget())->toBe('select')
        ->and(Field::belongsTo('group_id', Post::class)->getWidget())->toBe('combobox');
});

test('label falls back to humanized field name', function (): void {
    expect(Field::string('first_name')->getLabel())->toBe('First Name')
        ->and(Field::string('first_name')->label('Given name')->getLabel())->toBe('Given name');
});

test('password fields are write-only by default', function (): void {
    expect(Field::password('password')->isWriteOnly())->toBeTrue()
        ->and(Field::string('title')->isWriteOnly())->toBeFalse();
});

test('widget hint can be overridden', function (): void {
    expect(Field::bool('active')->widget('switch')->getWidget())->toBe('switch');
});

// ---------------------------------------------------------------
// Enum introspection
// ---------------------------------------------------------------

test('enum fields expose options with labels from the enum', function (): void {
    $options = Field::enum('status', PostStatus::class)->getOptions();

    expect($options)->toBe([
        ['value' => 'draft', 'label' => 'Draft'],
        ['value' => 'published', 'label' => 'Live'],
    ]);
});

test('only() restricts enum options to a subset of cases', function (): void {
    $field = Field::enum('status', PostStatus::class)->only([PostStatus::Draft]);

    expect($field->getOptionValues())->toBe(['draft']);
});

// ---------------------------------------------------------------
// FieldSet
// ---------------------------------------------------------------

test('field set keys fields by name and looks them up', function (): void {
    expect(array_keys(PostFields::all()))->toBe(['title', 'body', 'status', 'owner_id'])
        ->and(PostFields::field('title')?->type)->toBe(FieldType::String)
        ->and(PostFields::field('missing'))->toBeNull();
});

test('field set exposes raw rules for fields that declare them', function (): void {
    expect(PostFields::rules())->toBe(['title' => 'required|string|max:255']);
});

// ---------------------------------------------------------------
// Descriptor serialization
// ---------------------------------------------------------------

test('describeField serializes enum descriptor with options', function (): void {
    $serializer = new SchemaSerializer(app(SubjectRegistry::class));

    $descriptor = $serializer->describeField(Field::enum('status', PostStatus::class));

    expect($descriptor['type'])->toBe('enum')
        ->and($descriptor['widget'])->toBe('select')
        ->and($descriptor['options'])->toHaveCount(2);
});

test('describeField serializes relation descriptor with registry alias lookup', function (): void {
    $serializer = new SchemaSerializer(app(SubjectRegistry::class));

    $descriptor = $serializer->describeField(
        Field::belongsTo('post_id', Post::class)->display('title')->searchable(),
    );

    expect($descriptor['type'])->toBe('relation')
        ->and($descriptor['relation'])->toBe([
            'subject' => 'posts',
            'display' => 'title',
            'searchable' => true,
            'multiple' => false,
        ]);
});

test('describeField includes nullable, default, wire, and writeOnly only when set', function (): void {
    $serializer = new SchemaSerializer(app(SubjectRegistry::class));

    $plain = $serializer->describeField(Field::string('title'));
    $rich = $serializer->describeField(
        Field::bool('is_active')->nullable()->default(true)->wire('on_off'),
    );

    expect($plain)->not->toHaveKeys(['nullable', 'default', 'wire', 'writeOnly'])
        ->and($rich['nullable'])->toBeTrue()
        ->and($rich['default'])->toBeTrue()
        ->and($rich['wire'])->toBe('on_off');
});

test('effectiveRules adds type-implied, enum, relation, and nullable rules', function (): void {
    $serializer = new SchemaSerializer(app(SubjectRegistry::class));

    $enumRules = $serializer->effectiveRules(Field::enum('status', PostStatus::class), null);
    expect($enumRules)->toContainEqual(['rule' => 'in', 'params' => ['draft', 'published']]);

    $relationRules = $serializer->effectiveRules(Field::belongsTo('post_id', Post::class), null);
    expect($relationRules)->toContainEqual(['rule' => 'exists', 'serverOnly' => true]);

    $emailRules = $serializer->effectiveRules(Field::email('email')->rules('required'), null);
    expect($emailRules)->toContainEqual(['rule' => 'email']);

    $nullableRules = $serializer->effectiveRules(Field::text('body')->nullable(), null);
    expect($nullableRules)->toContainEqual(['rule' => 'nullable']);
});

test('effectiveRules lets a context override replace field rules', function (): void {
    $serializer = new SchemaSerializer(app(SubjectRegistry::class));
    $field = Field::string('title')->rules('required|max:255');

    $rules = $serializer->effectiveRules($field, 'required|max:80');

    expect($rules)->toContainEqual(['rule' => 'max', 'params' => [80]])
        ->and($rules)->not->toContainEqual(['rule' => 'max', 'params' => [255]]);
});
