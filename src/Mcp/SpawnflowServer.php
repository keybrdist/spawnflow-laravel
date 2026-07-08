<?php

namespace Spawnflow\Mcp;

use Laravel\Mcp\Server;

/**
 * The SpawnFlow MCP server — the contract, queryable and operable by agents.
 *
 * Two tool classes ride two transports:
 *  - dev-time (stdio, local env only): introspect the registry and schemas,
 *    evaluate eligibility verdicts, scaffold resources, regenerate types;
 *  - runtime (streamable HTTP, opt-in, authenticated): CRUD delegated 1:1
 *    to the Flow chain — ownership, contexts, eligibility and wire coercion
 *    are enforced exactly as on the HTTP path.
 *
 * The server adds ZERO new authorization logic and ZERO new definitions:
 * every tool delegates to the existing owner (Registry, SchemaSerializer,
 * Eligibility, Flow, artisan commands). Dev tools gate themselves via
 * eligibleForRegistration() — absent from tools/list, not runtime-guarded.
 */
class SpawnflowServer extends Server
{
    protected string $name = 'SpawnFlow';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        SpawnFlow exposes CRUD resources through a single machine-readable
        contract: field descriptors (type, widget, rules, wire format),
        per-role/state context variants, and JSON Logic eligibility rules.

        Discovery: `list-subjects` → `get-schema` (pass record_id for the
        record-state variant). Debug rules with `check-eligibility`
        (hypothetical form values → per-field verdicts). Dry-run writes with
        `validate-payload` — it never persists.

        Writes (create/update/delete tools, when enabled) run the full Flow
        chain: ownership is verified, context-ineligible fields are stripped,
        rule-ineligible values are discarded, wire formats are coerced. The
        persisted record is returned — trust it over the submitted payload.

        Read the `spawnflow://llms` resource for the full contract guide.
    MARKDOWN;

    protected array $tools = [
        Tools\ListSubjects::class,
        Tools\GetSchema::class,
        Tools\CheckEligibility::class,
        Tools\ValidatePayload::class,
        Tools\ScaffoldResource::class,
        Tools\GenerateTypes::class,
        Tools\ListRecords::class,
        Tools\GetRecord::class,
        Tools\CreateRecord::class,
        Tools\UpdateRecord::class,
        Tools\DeleteRecord::class,
        Tools\FieldOptions::class,
    ];

    protected array $resources = [
        Resources\LlmsGuide::class,
        Resources\SchemaContractDoc::class,
        Resources\EligibilityFixtures::class,
    ];

    protected array $prompts = [
        Prompts\AddResource::class,
        Prompts\DebugEligibility::class,
    ];
}
