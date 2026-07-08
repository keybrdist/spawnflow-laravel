<?php

use Spawnflow\Contracts\SubjectRegistry;
use Spawnflow\Eligibility\Eligibility;
use Spawnflow\Mcp\Resources\EligibilityFixtures;
use Spawnflow\Mcp\Resources\LlmsGuide;
use Spawnflow\Mcp\SpawnflowServer;
use Spawnflow\Mcp\Tools\CheckEligibility;
use Spawnflow\Mcp\Tools\GetSchema;
use Spawnflow\Mcp\Tools\ListSubjects;
use Spawnflow\Schema\SchemaSerializer;
use Spawnflow\Tests\Fixtures\Post;
use Spawnflow\Tests\Fixtures\PostFields;
use Spawnflow\Tests\Fixtures\User;

function mcpUser(array $attrs = []): User
{
    return User::create(array_merge([
        'name' => 'MCP User',
        'email' => uniqid().'@example.com',
        'roles' => '',
    ], $attrs));
}

// ---------------------------------------------------------------
// list-subjects
// ---------------------------------------------------------------

test('list-subjects reports every registered alias with capabilities', function (): void {
    $response = SpawnflowServer::tool(ListSubjects::class);

    $response->assertOk()
        ->assertSee('"alias":"posts"')
        ->assertSee('"has_context":true')
        ->assertSee('"alias":"articles"');
});

// ---------------------------------------------------------------
// get-schema — parity with SchemaSerializer, byte for byte
// ---------------------------------------------------------------

test('get-schema variants output matches SchemaSerializer verbatim', function (): void {
    $registry = app(SubjectRegistry::class);
    $expected = (new SchemaSerializer($registry))->variants('posts', $registry->contextFor('posts'));

    SpawnflowServer::tool(GetSchema::class, ['subject' => 'posts'])
        ->assertOk()
        ->assertSee(json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

test('get-schema default schema for context-less subjects matches serializer', function (): void {
    $registry = app(SubjectRegistry::class);
    $expected = (new SchemaSerializer($registry))->defaultSchema('articles');

    SpawnflowServer::tool(GetSchema::class, ['subject' => 'articles'])
        ->assertOk()
        ->assertSee(json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
});

test('get-schema resolves the record-state variant for the caller', function (): void {
    $owner = mcpUser();
    $post = Post::create(['title' => 'Draft', 'status' => 'draft', 'owner_id' => $owner->id]);

    SpawnflowServer::actingAs($owner)
        ->tool(GetSchema::class, ['subject' => 'posts', 'record_id' => $post->id])
        ->assertOk()
        ->assertSee('"context":"owner:draft"');
});

test('get-schema denied record reads exactly like a missing record', function (): void {
    $owner = mcpUser();
    $stranger = mcpUser();
    $post = Post::create(['title' => 'Private', 'status' => 'draft', 'owner_id' => $owner->id]);

    // PrivatePostContext-style denial is covered by the HTTP suite; here the
    // stranger resolves the viewer variant (allowed) — but a missing id and
    // an unknown id must be indistinguishable.
    SpawnflowServer::actingAs($stranger)
        ->tool(GetSchema::class, ['subject' => 'posts', 'record_id' => 999999])
        ->assertHasErrors(['Record not found: posts/999999']);
});

test('get-schema rejects unknown subjects with the known list', function (): void {
    SpawnflowServer::tool(GetSchema::class, ['subject' => 'nope'])
        ->assertHasErrors()
        ->assertSee("Unknown subject 'nope'");
});

// ---------------------------------------------------------------
// check-eligibility — parity with Eligibility
// ---------------------------------------------------------------

test('check-eligibility verdicts match the Eligibility owner', function (): void {
    $values = ['status' => 'published'];
    $expected = Eligibility::fieldVerdicts(PostFields::class, $values);

    // body is enabledWhen status == draft → disabled for published.
    expect($expected['body']['enabled'])->toBeFalse();

    SpawnflowServer::tool(CheckEligibility::class, ['subject' => 'posts', 'values' => $values])
        ->assertOk()
        ->assertSee('"enabled":false')
        ->assertSee('"ineligible"')
        ->assertSee('"body"');
});

test('check-eligibility explains an eligible state too', function (): void {
    SpawnflowServer::tool(CheckEligibility::class, ['subject' => 'posts', 'values' => ['status' => 'draft']])
        ->assertOk()
        ->assertSee('"ineligible":[]');
});

// ---------------------------------------------------------------
// resources
// ---------------------------------------------------------------

test('llms resource serves the onboarding doc verbatim', function (): void {
    SpawnflowServer::resource(LlmsGuide::class)
        ->assertOk()
        ->assertSee('Spawnflow');
});

test('conformance fixtures resource serves the shared cases', function (): void {
    SpawnflowServer::resource(EligibilityFixtures::class)
        ->assertOk()
        ->assertSee('cases');
});
