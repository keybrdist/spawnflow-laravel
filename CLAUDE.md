# SpawnFlow — agent working agreement

## Persona: own it end-to-end

This project is **self-contained and fully testable end-to-end**. The agent
operates it that way:

- **Never punt operable work to the user.** Registries (Packagist, npm),
  GitHub settings, CI, browser-driven config UIs, consumer-side install
  verification — all are the agent's to drive (browser automation included).
  The ONLY things handed to the user are true auth ceremonies the agent
  cannot perform: OTP/2FA prompts, org-creation identity decisions, naming
  calls.
- **If something isn't e2e-testable, that's a bug — fix the testability**,
  don't work around it. Every claim ("installable", "renders", "publishes")
  is verified from the consumer side in a clean environment, not assumed
  from a green unit suite. Precedent: the 3-command film rehearsal caught
  Laravel-13 incompatibility and the export-ignored stubs; clean-Node
  import verification caught the broken 0.2.1 ESM dist.
- **Work through failures autonomously.** A failed CI run, a 404 from a
  registry, a rejected token exchange: root-cause it from logs and fix it;
  report the story afterwards, not the blockage.

## Verification bar (what "done" means here)

- PHP: `vendor/bin/pest` (Testbench, sqlite; `--group=mysql-introspection`
  needs MySQL, runs in CI).
- JS: `cd js && npm test && npm run typecheck && npm run build` — the
  conformance suite (`resources/conformance/eligibility-fixtures.json`) pins
  PHP↔JS evaluator parity; change semantics there first, never in one runtime.
- Distribution claims: verify as a consumer — fresh app `composer require`,
  clean-dir `npm install` + native-Node ESM import, registry-item fetch.
- Quickstart claims: `demo/3-command-path.tape` (VHS) must re-render against
  a fresh Laravel app (`demo/setup.sh`).

## Project facts

- PHP package `spawnflow/spawnflow-laravel` (Packagist, tags `v*`).
  JS packages `@spawnflow-dx/core` + `@spawnflow-dx/react-shadcn` (npm) —
  the bare `spawnflow` npm name belongs to an unrelated CLI tool.
- Releases: tag push runs `.github/workflows/release.yml` (npm publish via
  OIDC trusted publishing, NPM_TOKEN secret as fallback; version-exists
  guard keeps PHP-only tags green).
- Contract is additive-only; one serializer feeds the endpoint, generator,
  renderers, and MCP — never introduce a second definition of a decision.
- Roadmap: `docs/roadmap.md` (public), `specs/spawnflow-roadmap.html`
  (horizons, gitignored), `.planning/` (spikes, gitignored).
- First production consumer: promolyltd/promoly-app (`api/`), Packagist dep.
