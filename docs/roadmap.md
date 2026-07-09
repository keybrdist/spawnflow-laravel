# Roadmap

Where SpawnFlow is going, and — just as deliberately — what it is **not**
building until real demand pulls it.

## Shipped

- **v0.1.0** (2026-03) — the fluent chain: `spawn → auth → resolve → ask →
  fields → validate → save → present`, context-enum field permissions.
- **v0.2.x** (2026-07) — the Living Contract: schema contract (types +
  validation + **eligibility**), restricted-JSON-Logic rules evaluated
  identically in PHP and JS, field groups, `@spawnflow-dx/core`, React (shadcn)
  and Livewire renderers, shadcn registry distribution, the 3-command path
  (`spawnflow:install` + `spawnflow:resource --generate`), SSE invalidation,
  wire-format coercion, MCP server. Laravel 11–13.

## In flight

- Public distribution is live: [Packagist](https://packagist.org/packages/spawnflow/spawnflow-laravel),
  npm ([`@spawnflow-dx/core`](https://www.npmjs.com/package/@spawnflow-dx/core),
  [`@spawnflow-dx/react-shadcn`](https://www.npmjs.com/package/@spawnflow-dx/react-shadcn)),
  and the shadcn registry item (raw-URL install).
- Adoption & hardening toward 1.0: the API stabilizes against real external
  consumers before any semver lock. Breaking-change wishes are collected and
  shipped together in 1.0 — not piecemeal.

## Demand-gated

These are parked **on purpose**, each behind an explicit trigger. If your
project hits one of these triggers, open an issue — that's exactly the
demand signal that unparks the work.

| Item | Trigger | Planned response |
|---|---|---|
| **Vue / Svelte renderers** | A concrete request from a real app | A skin over `@spawnflow-dx/core` (contract types, eligibility evaluator, Zod compiler are already framework-agnostic) — never a parallel implementation |
| **Deeper Precognition mode** | A real form needs live `unique:`/`exists:` feedback | Eligibility-aware precognition + client wiring for `serverOnly` rules; compiled Zod already covers client-checkable rules |
| **Zero / Postgres sync variant** | Sync-engine UX becomes table-stakes for plain CRUD | Fresh spike; the current HTTP + SSE-invalidation architecture is a deliberate choice, not an oversight |
| **spawnflow.dev registry hosting** | Raw GitHub registry URLs become a friction or trust problem | Static registry host + docs site |
| **MCP surface growth** | Agents hit tool-surface limits | More tools/prompts under the same rule: thin adapter, zero new authorization logic |

## Principles that shape every decision

1. **One declaration → derive everything.** FieldSet + Context declare;
   serializer, generator, renderers, and MCP all consume. No feature may
   introduce a second definition of a decision.
2. **Additive-only contract.** Schema-contract keys are added, never changed.
3. **Server stays authoritative.** Client-side evaluation is UX; the write
   path enforces everything.
4. **Known renderer families, not generic clients.** SpawnFlow ships the
   contract for its own renderers rather than aspiring to a universal
   hypermedia format.
5. **Demand-gated growth.** Renderer/transport proliferation without pull is
   how form libraries burn out; triggers, not ambition, open new work.
