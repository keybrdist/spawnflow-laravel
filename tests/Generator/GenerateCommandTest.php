<?php

use Spawnflow\Schema\Field;
use Spawnflow\Schema\FieldSet;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\QuotedEnum;

beforeEach(function (): void {
    $this->outputPath = sys_get_temp_dir().'/spawnflow-gen-'.uniqid();
    config()->set('spawnflow.generator', [
        'output_path' => $this->outputPath,
        'type_format' => 'typescript',
        'validation' => 'zod',
        'emit_client' => true,
        'emit_unions' => true,
    ]);
});

afterEach(function (): void {
    foreach (glob($this->outputPath.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->outputPath);
});

test('generates one module per subject plus index and client', function (): void {
    $this->artisan('spawnflow:generate')->assertSuccessful();

    expect(file_exists("{$this->outputPath}/posts.ts"))->toBeTrue()
        ->and(file_exists("{$this->outputPath}/articles.ts"))->toBeTrue()
        ->and(file_exists("{$this->outputPath}/client.ts"))->toBeTrue();

    $index = file_get_contents("{$this->outputPath}/index.ts");
    expect($index)->toContain("export * from './posts';")
        ->toContain("export * from './articles';")
        ->toContain("export * from './client';");
});

test('subject module carries field types, zod variants, and the union', function (): void {
    $this->artisan('spawnflow:generate')->assertSuccessful();

    $posts = file_get_contents("{$this->outputPath}/posts.ts");

    expect($posts)
        ->toContain('export type PostsFields = {')
        ->toContain("status: 'draft' | 'published';")
        ->toContain('export const postsOwnerDraftSchema = z.object({')
        ->toContain('title: z.string().min(1).max(255)')
        ->toContain("status: z.enum(['draft', 'published'])")
        ->toContain("'owner:draft': postsOwnerDraftSchema,")
        ->toContain('export type PostsContext = keyof typeof postsSchemas;')
        ->toContain('export type PostsVariant =')
        ->toContain("| { context: 'viewer'; editable: Record<string, never> }");
});

test('context-less subjects generate a single default variant with implied rules', function (): void {
    $this->artisan('spawnflow:generate')->assertSuccessful();

    $articles = file_get_contents("{$this->outputPath}/articles.ts");

    expect($articles)
        ->toContain('export const articlesDefaultSchema = z.object({')
        ->toContain('title: z.string().min(1).max(100)')
        ->toContain('post_id: z.number()')
        ->toContain('/* server: exists */');
});

test('on_off wire fields type as boolean-or-wire-string on both emitters', function (): void {
    config()->set('spawnflow.subjects', array_merge(config('spawnflow.subjects'), [
        'flags' => Post::class,
    ]));
    config()->set('spawnflow.fields', array_merge(config('spawnflow.fields'), [
        'flags' => (new class extends FieldSet
        {
            public static function fields(): array
            {
                return [
                    Field::bool('active')->wire('on_off')->nullable(),
                    Field::bool('plain'),
                ];
            }
        })::class,
    ]));

    $this->artisan('spawnflow:generate')->assertSuccessful();

    $flags = file_get_contents("{$this->outputPath}/flags.ts");

    // Writes accept logical booleans (server coerces); reads return the
    // stored 'on'/'off' strings — the type must admit both.
    expect($flags)
        ->toContain("active: boolean | 'on' | 'off' | null;")
        ->toContain("active: z.union([z.boolean(), z.literal('on'), z.literal('off')]).nullable()")
        ->toContain('plain: boolean;')
        ->toContain('plain: z.boolean()');
});

test('fails cleanly without an output path', function (): void {
    config()->set('spawnflow.generator', []);

    $this->artisan('spawnflow:generate')->assertFailed();
});

test('non-identifier field names and quotable enum values emit valid TypeScript', function (): void {
    config()->set('spawnflow.subjects', array_merge(config('spawnflow.subjects'), [
        'tricky' => Post::class,
    ]));
    config()->set('spawnflow.fields', array_merge(config('spawnflow.fields'), [
        'tricky' => (new class extends FieldSet
        {
            public static function fields(): array
            {
                return [
                    Field::string('billing-email'),
                    Field::bool('2fa_enabled'),
                    Field::enum('label', QuotedEnum::class),
                ];
            }
        })::class,
    ]));

    $this->artisan('spawnflow:generate')->assertSuccessful();

    $tricky = file_get_contents("{$this->outputPath}/tricky.ts");

    expect($tricky)
        // Non-identifier field names are quoted keys, not bare identifiers.
        ->toContain("'billing-email': string")
        ->toContain("'2fa_enabled': boolean")
        // Enum values with quotes/backslashes are escaped, not raw.
        ->toContain("'it\\'s'")
        ->toContain("'back\\\\slash'")
        // Line terminators are escaped — a raw newline inside a single-quoted
        // literal would make the generated file unparseable.
        ->toContain("'line\\nbreak'")
        ->not->toContain("'line\nbreak'");
});
