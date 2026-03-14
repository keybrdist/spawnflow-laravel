# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
