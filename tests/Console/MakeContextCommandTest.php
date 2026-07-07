<?php

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->target = app_path('Spawnflow/PostContext.php');
    File::delete($this->target);
});

afterEach(function (): void {
    File::delete($this->target);
});

test('scaffolds a FieldContext enum from the stub', function (): void {
    $this->artisan('make:spawnflow-context', ['name' => 'PostContext'])
        ->assertSuccessful();

    expect(File::exists($this->target))->toBeTrue();

    $contents = File::get($this->target);

    expect($contents)
        ->toContain('namespace App\Spawnflow;')
        ->toContain('enum PostContext: string implements FieldContext')
        ->toContain('public function editableFields(): array')
        ->toContain('public function validation(): array')
        ->toContain('public function visibleFields(): array')
        ->not->toContain('{{ class }}')
        ->not->toContain('{{ namespace }}');
});

test('refuses to overwrite without --force', function (): void {
    File::ensureDirectoryExists(dirname($this->target));
    File::put($this->target, '<?php // existing');

    $this->artisan('make:spawnflow-context', ['name' => 'PostContext'])
        ->expectsOutputToContain('already exists');

    expect(File::get($this->target))->toBe('<?php // existing');

    $this->artisan('make:spawnflow-context', ['name' => 'PostContext', '--force' => true])
        ->assertSuccessful();

    expect(File::get($this->target))->toContain('enum PostContext');
});
