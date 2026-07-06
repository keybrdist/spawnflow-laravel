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

### Changed
- `SchemaController` responses now follow schema contract v1 (`spawnflow: "1"`, joined descriptors, structured rules per variant); previous ad-hoc response shapes replaced

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
