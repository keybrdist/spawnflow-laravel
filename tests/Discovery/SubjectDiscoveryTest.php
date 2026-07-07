<?php

use Illuminate\Support\Facades\File;
use Spawnflow\ConfigSubjectRegistry;
use Spawnflow\Discovery\SubjectDiscovery;
use Spawnflow\Tests\Fixtures\Discovery\DiscoveredBriefFields;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\PostContext;

beforeEach(function (): void {
    config()->set('spawnflow.discovery_path', __DIR__.'/../Fixtures/Discovery');
    File::delete(SubjectDiscovery::cachePath());
});

afterEach(function (): void {
    File::delete(SubjectDiscovery::cachePath());
});

test('scan finds FieldSets carrying #[SpawnSubject]', function (): void {
    $maps = SubjectDiscovery::scan();

    expect($maps['subjects']['briefs'])->toBe(Post::class)
        ->and($maps['fields']['briefs'])->toBe(DiscoveredBriefFields::class)
        ->and($maps['contexts']['briefs'])->toBe(PostContext::class);
});

test('the registry resolves discovered subjects without any config entry', function (): void {
    $registry = new ConfigSubjectRegistry;

    expect($registry->resolve('briefs'))->toBeInstanceOf(Post::class)
        ->and($registry->fieldsFor('briefs'))->toBe(DiscoveredBriefFields::class)
        ->and($registry->contextFor('briefs'))->toBe(PostContext::class);
});

test('config entries override discovered ones on conflict', function (): void {
    config()->set('spawnflow.fields.briefs', Spawnflow\Tests\Fixtures\PostFields::class);

    expect((new ConfigSubjectRegistry)->fieldsFor('briefs'))
        ->toBe(Spawnflow\Tests\Fixtures\PostFields::class);
});

test('discovery can be disabled', function (): void {
    config()->set('spawnflow.discovery', false);

    expect(fn () => (new ConfigSubjectRegistry)->resolve('briefs'))
        ->toThrow(Spawnflow\Exceptions\UnresolvableSubjectException::class);
});

test('spawnflow:cache freezes the scan and spawnflow:clear removes it', function (): void {
    $this->artisan('spawnflow:cache')->assertSuccessful();

    expect(is_file(SubjectDiscovery::cachePath()))->toBeTrue();

    // The cache, not a live scan, is what discover() returns now.
    config()->set('spawnflow.discovery_path', '/nonexistent');
    expect(SubjectDiscovery::discover()['subjects'])->toHaveKey('briefs');

    $this->artisan('spawnflow:clear')->assertSuccessful();
    expect(is_file(SubjectDiscovery::cachePath()))->toBeFalse();

    // Uncached + empty path -> nothing discovered (dev scan is live).
    expect(SubjectDiscovery::discover()['subjects'])->toBe([]);
});
