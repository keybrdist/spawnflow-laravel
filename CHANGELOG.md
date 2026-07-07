# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Schema contract v1** (`docs/schema-contract.md`) — versioned, machine-readable field schema for type-aware form rendering and client-side validation generation
- `Schema\Field` — type-aware field descriptor (type, widget, label, rules, nullable/default, wire format, write-only) with named constructors (`string`, `text`, `int`, `float`, `bool`, `date`, `datetime`, `email`, `password`, `json`, `file`, `enum`, `belongsTo`, `belongsToMany`)
- `Schema\FieldSet` — per-subject field descriptor registry, wired via `config('spawnflow.fields')`
- `Schema\FieldType` — field type enum with default widget hints and implied validation rules
- Enum introspection: `Field::enum()` derives `{value, label}` options (via `label()` method or humanized case names), an `in:` rule, and a select widget from one backed enum; `only()` restricts to a case subset
- Relation descriptors: `Field::belongsTo()/belongsToMany()` with `display()`/`searchable()`, registry alias resolution, and implied server-only `exists` rule
- `Schema\RuleSerializer` — Laravel rules → structured `[{rule, params, serverOnly}]`; client-safe allowlist, database/closure/unknown rules flagged `serverOnly`, stringable rule objects supported
- `Schema\SchemaSerializer` — single serializer behind the schema endpoint (and future generator)
- `SubjectRegistry::fieldsFor()` and `aliasFor()` (reverse model → alias lookup)
- Test coverage for schema routes, field descriptors, and rule serialization

- **Validation authority** — one rule source, three consumers:
  - `Validation\RuleResolver` — effective raw rules from FieldSet descriptors + context overrides + descriptor-implied rules (type, enum `in:`, relation `exists:{table},{key}`, nullability); mirrors the schema contract exactly
  - `Flow::validate()` now sources rules automatically: explicit argument → context `validation()` → field base rules → implied rules
  - `Http\SpawnflowFormRequest` — FormRequest bridge for conventional controllers (`protected string $subject`)
  - Precognition support: `Precognition` header runs validate-only and halts the chain with 204 + Precognition headers; `Precognition-Validate-Only` scopes fields
- `ContextResolver` — single definition of context resolution (synthetic record on create), shared by `Flow::fields()`, the schema endpoint, and the FormRequest bridge
- `Field::impliedRawRules()` — single definition of descriptor-implied rules, consumed by both the schema serializer and the server-side rule resolver

- **React renderer + demo** (`js/`) — `@spawnflow/react-shadcn`: schema-driven `<SpawnForm>` (react-hook-form + Zod), shadcn-styled widget registry (input, textarea, number, checkbox, select, async searchable combobox, datepicker), runtime rule→Zod compiler, `confirmed` confirmation pairing, serverOnly "server-checked" hints, 422 error mapping, context-aware disabled fields, `createHttpClient` for real Spawnflow routes; Vite demo app with registration / change-password / edit-profile / billing forms and a persona switcher (three billing variants from one component)
- **Options endpoint** — `GET /spawnflow/options/{subject}/{field}?q=&page=&per_page=`: `{value, label}` pages for relation select/combobox widgets; ownership-scoped by default (`unscoped()` for shared lookups), `q` search on the display column for `searchable()` fields, `next_page` pagination; relation descriptors now carry `options_url` when schema routes are enabled; generated client gains `options()`
- **Frontend generator** — `php artisan spawnflow:generate` (fills the long-dormant `generator` config block):
  - `Generator\TypeScriptGenerator` — per-subject modules: field-map types, field metadata, per-variant Zod schemas, context-keyed maps, discriminated variant unions (`emit_unions`), index, optional fetch client (`emit_client`)
  - `Generator\ZodCompiler` — structured rules → Zod expressions; serverOnly rules emit `/* server: ... */` comments, unmapped rules `/* unhandled: ... */` — nothing silently dropped
  - Generator consumes the contract via the same `SchemaSerializer` as the live endpoint — no drift possible
  - Generated output verified against `tsc --strict` with zod

- **`make:spawnflow-context` command** — scaffolds a `FieldContext` enum from the published stub into `App\Spawnflow`; `--force` overwrites. Consumes the previously-orphaned `stubs/context-enum.stub`.

- **Eligibility rules** — the reactive axis of the contract (context variants stay the who×record-state axis):
  - `Field::visibleWhen()/hiddenWhen()/enabledWhen()/disabledWhen()` — per-field `{effect, condition}` rules over sibling field VALUES; `->serverResolved()` ships the verdict only (no client re-eval)
  - `Eligibility\Condition` — restricted JSON Logic evaluator: fixed op allowlist (`==` strict, `!=`, `>`, `<`, `>=`, `<=`, `and`, `or`, `!`, `in`, `var`, `missing`), explicit cross-runtime truthiness (`"0"` truthy), errors fail CLOSED regardless of effect polarity
  - Contract keys (additive): `eligibility` envelopes per field, `serverResolved`, top-level `resolved` verdicts — record values on the resolved shape, field defaults on variants/default shapes (create baseline)
  - Declaration-time guard: serialization throws `InvalidEligibilityException` when a rule references an undeclared field or one a exposing variant cannot see
  - Write-path enforcement: `Flow::save()` discards rule-ineligible field values (clear-on-ineligible); `Flow::validate()` skips their rules; rules are never cosmetic
  - `resources/conformance/eligibility-fixtures.json` — single cross-runtime conformance suite (Pest now, vitest via `@spawnflow/core`)
  - No cycles by construction: conditions reference values, never other fields' eligibility
- **`@spawnflow/core`** — framework-agnostic TS core extracted from the React renderer (contract types, rule→Zod compiler, HTTP client) plus the JS eligibility evaluator: same restricted op allowlist and fail-closed semantics as PHP, verified against the SAME `resources/conformance/eligibility-fixtures.json` (46 cases, both suites); `fieldVerdicts()` mirrors `Eligibility::fieldVerdicts()` incl. group AND-composition and serverResolved verdict pass-through
- **react-shadcn eligibility wiring** — `@spawnflow/react-shadcn` now consumes `@spawnflow/core`; `<SpawnForm>` re-evaluates rules live as values change (hidden fields unmount, disabled fields reject input), renders groups as bordered sections, and discards rule-ineligible values on submit (client mirror of clear-on-ineligible)
- Numeric equality parity: PHP `==` compares numbers by value (int 1 == float 1.0) matching JS's single number type; pinned by fixtures
- **Field groups** — `Schema\Group`: first-class eligibility nodes (sections / wizard steps) declared via `FieldSet::groups()`; same rule envelope as fields; AND-composition (hidden group hides members regardless of their own rules); single-membership validated; wire keys `groups` + `resolved_groups`; per-field `resolved` verdicts are final (own ∧ group); guard covers group rules (variant exposing any member must see the references)

- **The 3-command path** — `spawnflow:install` + `spawnflow:resource {Name} --generate`:
  - `#[SpawnSubject('alias', model: ..., context: ...)]` attribute + `Discovery\SubjectDiscovery` — FieldSets under `app/Spawnflow` self-register; config overrides attributes on conflict; `spawnflow:cache`/`spawnflow:clear` freeze/unfreeze the scan (presence-decides, like Laravel's bootstrap caches); generating a resource busts the cache
  - `Generator\TableIntrospector` — real columns + FK constraints → Field descriptor lines (varchar/text/tinyint(1)/decimal/date/datetime/json/enum→`in:` scaffold, FKs→`belongsTo` when the model exists); PK, timestamps, and the ownership column never become descriptors; make-time only, generated files stay canonical
  - `Generator\Scaffolder` — ONE stub pipeline for `make:spawnflow-context` and `spawnflow:resource` (tokenized stubs, shared renderer); `stubs/fieldset.stub` added
  - MySQL-service CI job for introspection tests (`--group=mysql-introspection`, env-gated, skips locally without MySQL); JS CI job (conformance + typecheck + demo build)

### Changed
- `SchemaController` responses now follow schema contract v1 (`spawnflow: "1"`, joined descriptors, structured rules per variant); previous ad-hoc response shapes replaced
- Resolved schema endpoint (`/schema/{subject}/{id}`) no longer requires ownership: resolution is read-only, any authenticated user gets their variant (viewer cases now reachable); missing record → 404
- Resolved schema exposes only the union of the variant's editable and visible fields
- Implied relation `exists` rules are fully qualified (`exists:{table},{key}`) in both schema output and server enforcement

## [0.1.0] - 2026-03-14

### Added
- `Flow` — fluent chain engine: `spawn → auth → resolve → ask → fields → validate → save → present`
- `FieldContext` interface for context enums (discriminated union field-level permissions)
- `SubjectRegistry` interface and `ConfigSubjectRegistry` implementation
- `SpawnflowController` — generic 4-route CRUD controller
- `SchemaController` — field permission schema endpoint (`/spawnflow/schema/{subject}/{id?}`)
- `HasSpawnflow` model trait with `scopeOwnedBy`
- `Presentable` interface for custom response transformers
- Exception classes: `UnauthenticatedException`, `OwnershipException`, `UnresolvableSubjectException`, `ForbiddenFieldAccessException`, `StateException`
- `gate()` for arbitrary authorization logic
- `after()` for post-operation side effects
- `list()` with ownership scoping, pagination, and validated sorting
- Configurable ownership column and user key
- Optional auto-registered schema routes (behind feature flag)
- Publishable config and stubs
- 29 Pest tests covering all chain methods
- Support for Laravel 11 and 12, PHP 8.2+
