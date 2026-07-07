<?php

use Illuminate\Support\Facades\File;
use Spawnflow\Discovery\SubjectDiscovery;

beforeEach(function (): void {
    $this->directory = app_path('Spawnflow');
    File::deleteDirectory($this->directory);
    File::delete(SubjectDiscovery::cachePath());
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
    File::delete(SubjectDiscovery::cachePath());
});

test('scaffolds a self-registering FieldSet and context without --generate', function (): void {
    $this->artisan('spawnflow:resource', ['name' => 'Brief'])->assertSuccessful();

    $fields = File::get("{$this->directory}/BriefFields.php");
    $context = File::get("{$this->directory}/BriefContext.php");

    expect($fields)
        ->toContain("#[SpawnSubject('briefs', model: \\App\\Models\\Brief::class, context: \\App\\Spawnflow\\BriefContext::class)]")
        ->toContain('class BriefFields extends FieldSet')
        ->toContain("// Field::string('title')")
        ->not->toContain('{{');

    expect($context)
        ->toContain('enum BriefContext: string implements FieldContext')
        ->not->toContain('{{');
});

test('--generate infers field descriptors from the real table', function (): void {
    $this->artisan('spawnflow:resource', [
        'name' => 'Post',
        '--generate' => true,
        '--model' => Spawnflow\Tests\Fixtures\Post::class,
    ])->assertSuccessful();

    $fields = File::get("{$this->directory}/PostFields.php");
    $context = File::get("{$this->directory}/PostContext.php");

    expect($fields)
        ->toContain("Field::string('title')->rules('required')")
        ->toContain("Field::text('body')->nullable()")
        ->toContain("Field::string('status')")
        ->toContain("->default('draft')")
        // Ownership column and timestamps never become descriptors.
        ->not->toContain("Field::int('owner_id')")
        ->not->toContain("'created_at')");

    expect($context)
        ->toContain("'title',")
        ->toContain("'status',")
        // Timestamps are visible, not editable.
        ->toContain("'created_at',");
});

test('refuses to overwrite without --force', function (): void {
    File::ensureDirectoryExists($this->directory);
    File::put("{$this->directory}/BriefFields.php", '<?php // existing');

    $this->artisan('spawnflow:resource', ['name' => 'Brief'])->assertFailed();

    expect(File::get("{$this->directory}/BriefFields.php"))->toBe('<?php // existing');

    $this->artisan('spawnflow:resource', ['name' => 'Brief', '--force' => true])->assertSuccessful();

    expect(File::get("{$this->directory}/BriefFields.php"))->toContain('class BriefFields');
});

test('generating a resource busts the discovery cache', function (): void {
    File::put(SubjectDiscovery::cachePath(), '<?php return ["subjects" => [], "contexts" => [], "fields" => []];');

    $this->artisan('spawnflow:resource', ['name' => 'Brief'])->assertSuccessful();

    expect(is_file(SubjectDiscovery::cachePath()))->toBeFalse();
});

test('--no-context skips the context enum and the attribute context arg', function (): void {
    $this->artisan('spawnflow:resource', ['name' => 'Brief', '--no-context' => true])->assertSuccessful();

    expect(File::exists("{$this->directory}/BriefContext.php"))->toBeFalse()
        ->and(File::get("{$this->directory}/BriefFields.php"))
        ->toContain("#[SpawnSubject('briefs', model: \\App\\Models\\Brief::class)]")
        ->not->toContain('context:');
});
