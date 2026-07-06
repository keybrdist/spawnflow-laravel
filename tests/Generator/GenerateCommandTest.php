<?php

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

test('fails cleanly without an output path', function (): void {
    config()->set('spawnflow.generator', []);

    $this->artisan('spawnflow:generate')->assertFailed();
});
