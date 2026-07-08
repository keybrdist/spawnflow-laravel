# SpawnFlow MCP Server

The contract, queryable and operable by AI agents over the Model Context
Protocol. The server is a thin adapter: every tool delegates to an existing
owner (registry, `SchemaSerializer`, `Eligibility`, the `Flow` chain, artisan
commands) — it adds **zero new authorization logic and zero new definitions**.

## Enabling

Requires `laravel/mcp` (suggested dependency):

```bash
composer require laravel/mcp
```

```php
// config/spawnflow.php
'mcp' => [
    'enabled' => true,            // stdio server (default false — off = no-op)
    'web' => false,               // streamable HTTP transport (opt-in)
    'web_route' => '/mcp/spawnflow',
    'web_middleware' => ['auth:api', 'throttle:60,1'],
],
```

## Connecting

**Claude Code** (local stdio, development):

```bash
claude mcp add spawnflow -- php artisan mcp:start spawnflow
```

**Codex** (`~/.codex/config.toml`):

```toml
[mcp_servers.spawnflow]
command = "php"
args = ["artisan", "mcp:start", "spawnflow"]
```

**Remote (HTTP)** — enable `mcp.web`, then point any MCP client at
`POST https://your-app.test/mcp/spawnflow` with a bearer token. The token's
user IS the Flow user; there is no impersonation parameter.

Runs cleanly alongside [Laravel Boost](https://github.com/laravel/boost)'s
MCP server (`laravel-boost` handle) — Boost answers "what is this Laravel
app" (schema, logs, docs search); SpawnFlow answers "what is this app's data
contract". Connect an agent to both.

## Tools

| Tool | Class | Notes |
|---|---|---|
| `list-subjects` | dev + runtime | Registry dump. Model class names ship only in the local environment. |
| `get-schema` | dev + runtime | `SchemaSerializer` output verbatim (no drift). Pass `record_id` for the caller's record-state variant — denied reads back exactly like missing (no existence oracle). |
| `check-eligibility` | dev + runtime | Hypothetical form values → per-field/group verdicts + the discard list. Pure, no DB. |
| `validate-payload` | dev + runtime | Dry-run of `Flow::validate` — never writes. Rule-ineligible fields skip validation (discard semantics), wire coercion applies first. |
| `scaffold-resource` | **local + stdio only** | Delegates to `spawnflow:resource --generate`; returns the generated FieldSet for review. |
| `generate-types` | **local + stdio only** | Delegates to `spawnflow:generate`. |
| `list-records` / `get-record` | runtime | Ownership-scoped; responses filtered by the resolved context's `visibleFields()`. |
| `create-record` / `update-record` | runtime | Full Flow chain; returns the **persisted** record — the chain may discard context-/rule-ineligible fields. |
| `delete-record` | runtime | Single id only, `ask()`-verified. No bulk over MCP. |
| `field-options` | runtime | Relation options (searchable selects), ownership-scoped with the fail-closed missing-column guard. |

Dev tools gate themselves via `eligibleForRegistration()` — outside the local
environment (or over HTTP) they are **absent from `tools/list`**, not
runtime-guarded. Runtime CRUD tools apply the same principle the other way:
they act AS a user, so without an authenticated user (a bare stdio session)
they too are absent from discovery; the web transport's auth middleware
guarantees one.

## Resources

- `spawnflow://llms` — the LLM onboarding doc (`llms.txt`)
- `spawnflow://docs/schema-contract` — contract v1 reference
- `spawnflow://conformance/eligibility-fixtures` — the shared PHP↔JS rule-evaluation cases

## Prompts

- `add-resource` — scaffold → review → eligibility → regenerate types
- `debug-eligibility` — bisect why a field is hidden/disabled/discarded

## Security model

| Concern | Control |
|---|---|
| Server enabled at all | `spawnflow.mcp.enabled` — default false; registration is a no-op when off |
| Dev tools in production | Registered only in local env over stdio; never exposed over HTTP |
| Runtime identity | HTTP behind `auth:api`; the token's user IS the Flow user |
| Write amplification | Throttle middleware; single-id delete only |
| Cross-tenant probing | Ownership denials read exactly like missing records |
