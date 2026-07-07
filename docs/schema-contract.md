# Spawnflow Schema Contract — v1

Status: **Draft** · Version field: `"spawnflow": "1"`

The schema contract is the machine-readable description of a subject's fields
that Spawnflow serves to frontends. It is the single source of truth for
type-aware form rendering, client-side validation generation (Zod), and
TypeScript type generation. Both the live schema endpoint and the static
generator emit through one serializer (`Spawnflow\Schema\SchemaSerializer`),
so the two can never drift.

Treat this contract like an API: additive changes bump nothing; breaking
changes bump the `spawnflow` version string.

---

## Endpoints

Registered when `config('spawnflow.schema_routes')` is true, behind
`schema_middleware`:

| Route | Returns |
|---|---|
| `GET /spawnflow/schema/{subject}` | [All-variants schema](#all-variants-schema) — descriptors + every context case |
| `GET /spawnflow/schema/{subject}/{id}` | [Resolved schema](#resolved-schema) — the caller's variant for one record |

Unknown subject → `404 {"error": "Unknown subject: x"}`.

---

## Sources of truth

Three declarations feed the contract:

1. **`FieldSet`** (`config('spawnflow.fields')`) — one class per subject
   declaring `Field` descriptors: type, widget, label, base rules, enum
   options, relation semantics, wire format. The identity of each field.
2. **`FieldContext`** (`config('spawnflow.contexts')`) — the permission
   layer: per case, which fields are editable/visible and optional per-case
   rule overrides.
3. **The registry** (`config('spawnflow.subjects')`) — alias ↔ model mapping,
   used for relation `subject` resolution.

**Rule precedence:** a context case's `validation()` entry for a field
*replaces* the field's base rules for that variant. Rules implied by the
descriptor (type, enum membership, relation existence, nullability) are
always appended unless the effective rules already contain a rule of the
same name.

Subjects without a `FieldSet` still produce schemas: undeclared fields get a
minimal inferred descriptor (`type: "string"`, `widget: "input"`, humanized
label).

---

## Field descriptor

The per-field identity object. Keys are omitted when at their default.

```json
{
  "type": "enum",
  "widget": "select",
  "label": "Status",
  "nullable": true,
  "default": "draft",
  "wire": "on_off",
  "writeOnly": true,
  "options": [ {"value": "draft", "label": "Draft"} ],
  "relation": {
    "subject": "groups",
    "display": "name",
    "searchable": true,
    "multiple": false
  }
}
```

| Key | Type | Notes |
|---|---|---|
| `type` | string | `string · text · int · float · bool · date · datetime · email · password · enum · relation · json · file` |
| `widget` | string | Renderer hint. Defaults per type: `input · textarea · number · checkbox · datepicker · datetimepicker · password · select · combobox · json · file`. Overridable per field. |
| `label` | string | Explicit or humanized field name. |
| `nullable` | bool | Present only when true. |
| `default` | mixed | Present only when set. |
| `wire` | string | Declared wire format for legacy coercions (e.g. `on_off` for booleans stored as `'on'/'off'`). Both sides coerce from this one declaration. |
| `writeOnly` | bool | Accepted on write, never in responses (passwords). Present only when true. |
| `options` | array | Enum fields only. `{value, label}` pairs; labels come from a `label()` method on the enum when defined, else humanized case names. Respects `only()` restrictions. |
| `relation` | object | Relation fields only. `subject` is the registered alias of the related model (null if unregistered). `options_url` is present when the schema routes are enabled — the options endpoint for this field. |
| `eligibility` | array | Rule-bearing fields only. `{effect, condition}` envelopes — see [Eligibility](#eligibility-rules). Absent when the field is `serverResolved`. |
| `serverResolved` | bool | Rule-bearing fields whose condition stays server-side. Clients get only the computed verdict (top-level `resolved`) and re-fetch to refresh it. Present only when true. |

---

## Structured rules

Validation rules are serialized structurally, not as pipe strings, so
consumers compile them mechanically (e.g. to Zod) without a Laravel rule
parser:

```json
[
  {"rule": "required"},
  {"rule": "max", "params": [255]},
  {"rule": "in", "params": ["draft", "published"]},
  {"rule": "unique", "params": ["users", "email"], "serverOnly": true}
]
```

- `params` — omitted when the rule takes none. Numeric params are numbers.
  `regex`, `not_regex`, and `date_format` params are never comma-split.
- `serverOnly: true` — the honest boundary. Present on any rule a static
  client validator cannot evaluate: database rules (`unique`, `exists`),
  closures (`{"rule": "closure"}`), non-stringable rule objects, and any
  rule outside the client-safe allowlist
  (`Spawnflow\Schema\RuleSerializer::CLIENT_RULES`). Frontends must treat
  fields carrying `serverOnly` rules as requiring a server validation pass
  (Precognition-style) for full correctness.
- Stringable rule objects (`Rule::in(...)`, `Rule::enum(...)`) serialize via
  their string form; `Rule::enum` therefore emits a client-checkable `in`.

**Implied rules** appended automatically (when not already present by name):

| Descriptor | Implied |
|---|---|
| `int` / `float` / `bool` / `date` / `datetime` / `email` | `integer` / `numeric` / `boolean` / `date` / `date` / `email` |
| `enum` | `in` with the option values |
| `relation` | `exists` (serverOnly) |
| `nullable()` | `nullable` (unless `required` present) |

---

## Eligibility rules

The reactive axis of the contract, orthogonal to context variants:

- **Context variants** answer *who* may touch a field in *what record
  state* — resolved server-side, coarse-grained, the authorization layer.
- **Eligibility rules** answer *given the form's current values, is this
  field visible/enabled?* — declared per field, shipped to the client,
  re-evaluated live as sibling fields change.

A field is effectively eligible only when its variant grants it AND its
rules pass. Rules may not reference roles — role concerns belong to the
variant axis by design.

### Envelope

```json
"body": {
  "type": "text", "widget": "textarea", "label": "Body",
  "eligibility": [
    {"effect": "enable", "condition": {"==": [{"var": "status"}, "draft"]}}
  ]
}
```

| Effect | Meaning |
|---|---|
| `show` | Visible iff the condition passes |
| `hide` | Hidden iff the condition passes |
| `enable` | Editable iff the condition passes (rendered but disabled otherwise) |
| `disable` | Disabled iff the condition passes |

Multiple envelopes AND together per axis. A field with no rule on an axis
defaults to eligible on that axis.

Declared in the FieldSet: `->visibleWhen($condition)`, `->hiddenWhen()`,
`->enabledWhen()`, `->disabledWhen()`, plus `->serverResolved()`.

### Conditions — restricted JSON Logic

Conditions are a fixed-allowlist subset of JSON Logic, evaluated
identically by `Spawnflow\Eligibility\Condition` (PHP) and
`@spawnflow/core` (JS); the shared behavior is pinned by
`resources/conformance/eligibility-fixtures.json`, which both test suites
run.

| Operator | Notes |
|---|---|
| `var` | `{"var": "name"}` or `{"var": ["name", default]}`. Dot paths supported. Absent key without a default is an **error** (fail-closed) — but evaluation data always contains every declared field (null when unset), so this only bites undeclared references, which serialization rejects. |
| `missing` | `{"missing": ["a", "b"]}` → array of absent names (truthy when any missing). The only operator that never errors on absence. |
| `==` / `!=` | **Strict** (`===` semantics). `"1" == 1` is false. |
| `>` `<` `>=` `<=` | Numbers only; numeric strings are an error. |
| `and` / `or` | Non-empty list of conditions. |
| `!` | Negation. |
| `in` | Needle in array (strict), or substring when both are strings. |

Truthiness is explicit and identical in both runtimes: `null`/`false`
falsy; numbers falsy at zero; strings falsy when empty (**`"0"` is
truthy**); arrays falsy when empty.

**Fail-closed:** any evaluation error (unknown operator, malformed node,
bad reference) produces the restrictive outcome — hidden/disabled —
regardless of the effect's polarity.

**No cycles by construction:** conditions reference field *values*, never
other fields' eligibility, so circular visibility is impossible.

### Resolved verdicts

Every schema shape carries a top-level `resolved` key with the
server-computed verdict per rule-bearing field:

```json
"resolved": {
  "body": {"visible": true, "enabled": false}
}
```

- **Resolved schema** — evaluated against the record's current values.
- **Variants / default schema** (create) — evaluated against field
  defaults, exactly what the client's initial form state sees.
- Evaluation data is the full declared field map — absent fields present
  as their default, else null — so both runtimes evaluate the same shape.

Clients seed initial field state from `resolved` and re-evaluate
`eligibility` conditions locally as values change; `serverResolved`
fields have no client-side condition and refresh only by re-fetching.

### Groups

Groups are first-class eligibility nodes: named, ordered sections (or
wizard steps) carrying the SAME envelope as a leaf field. Declared on the
FieldSet:

```php
public static function groups(): array
{
    return [
        Group::make('billing', ['vat_id', 'billing_country'])
            ->label('Billing')
            ->visibleWhen(['==' => [['var' => 'type'], 'business']]),
    ];
}
```

Wire shape (all schema shapes, when groups exist):

```json
"groups": [
  {
    "name": "billing",
    "label": "Billing",
    "fields": ["vat_id", "billing_country"],
    "eligibility": [{"effect": "show", "condition": {"==": [{"var": "type"}, "business"]}}]
  }
],
"resolved_groups": { "billing": {"visible": false, "enabled": true} }
```

- **Composition is AND:** a hidden group hides its members regardless of
  their own rules; a disabled group disables them. The per-field
  `resolved` verdicts are FINAL (own rules ∧ group).
- A field belongs to **at most one group**; membership of an undeclared
  field, or double membership, throws at declaration time.
- Ungrouped fields render outside any section, in declaration order.
- Groups support `->serverResolved()` with the same semantics as fields.
- Renderers treat `groups` as sections; a wizard is the same contract
  rendered one group per step.

### Visibility guard

Serialization **throws** (`InvalidEligibilityException`) when a rule
references an undeclared field, or a field that some variant exposing the
rule-bearing field cannot see — the client could never re-evaluate the
condition. Mark the field `->serverResolved()` to opt out of client
re-evaluation instead.

### Write-path enforcement (clear-on-ineligible)

Rules are enforced, never cosmetic. For the intended post-write state
(record values or defaults, overlaid with the submitted data):

- **`Flow::save()`** discards values for rule-ineligible fields — a
  crafted payload cannot write through a rule.
- **`Flow::validate()`** skips ineligible fields' validation rules (their
  values are discarded, so failing them would reject discards). Explicit
  rules passed to `validate()` are the caller's override and stay
  untouched.

Discard-on-write is the contract's answer to "the field became ineligible
while it still held a value": the stale value is dropped, not persisted.

---

## Resolved schema

`GET /spawnflow/schema/{subject}/{id}` — the variant for *this caller* on
*this record*. Fields shown: union of the resolved case's editable and
visible fields. `rules` appears only on editable fields.

```json
{
  "spawnflow": "1",
  "resource": "posts",
  "context": "owner:draft",
  "fields": {
    "title": {
      "type": "string", "widget": "input", "label": "Title",
      "editable": true, "visible": true,
      "rules": [{"rule": "required"}, {"rule": "string"}, {"rule": "max", "params": [255]}]
    },
    "status": {
      "type": "enum", "widget": "select", "label": "Status",
      "options": [{"value": "draft", "label": "Draft"}, {"value": "published", "label": "Live"}],
      "editable": true, "visible": true,
      "rules": [{"rule": "in", "params": ["draft", "published"]}]
    },
    "owner_id": {
      "type": "int", "widget": "number", "label": "Owner",
      "editable": false, "visible": true
    }
  }
}
```

Resolution is **read-only and ownership-free**: any authenticated user gets
their variant for the record — owners resolve owner cases, everyone else
resolves whatever the context enum decides (e.g. a viewer case). The context
enum is the authorization decision. Missing record → 404; unauthenticated →
401. The resolved schema exposes only the union of the variant's editable
and visible fields.

---

## All-variants schema

`GET /spawnflow/schema/{subject}` — descriptors once, then the discriminated
union of context cases. `context` is the discriminator (maps 1:1 to a
TypeScript discriminated union).

```json
{
  "spawnflow": "1",
  "resource": "posts",
  "fields": {
    "title":  { "type": "string", "widget": "input", "label": "Title" },
    "status": { "type": "enum", "widget": "select", "label": "Status", "options": [...] },
    "owner_id": { "type": "int", "widget": "number", "label": "Owner" }
  },
  "variants": [
    {
      "context": "owner:draft",
      "editable_fields": ["title", "body", "status"],
      "visible_fields": ["id", "title", "body", "status", "owner_id", "created_at", "updated_at"],
      "rules": {
        "title": [{"rule": "required"}, {"rule": "string"}, {"rule": "max", "params": [255]}],
        "body": [{"rule": "nullable"}, {"rule": "string"}],
        "status": [{"rule": "in", "params": ["draft", "published"]}]
      }
    },
    { "context": "owner:published", "editable_fields": ["title"], "...": "..." },
    { "context": "viewer", "editable_fields": [], "visible_fields": ["id", "title", "status"], "rules": {} }
  ]
}
```

Descriptors live once at the top; variants carry only permissions and
effective rules. Consumers join by field name.

## Default schema

Subjects with no `FieldContext` return a single-variant form — every
declared field editable and visible with its base rules:

```json
{ "spawnflow": "1", "resource": "posts", "context": "default", "fields": { "...": "..." } }
```

---

## Options endpoint

`GET /spawnflow/options/{subject}/{field}?q=&page=&per_page=` — the data
source behind relation select/combobox widgets. Registered alongside the
schema routes, behind the same middleware; requires authentication.

```json
{
  "options": [ {"value": 7, "label": "Marketing"}, {"value": 12, "label": "Sales"} ],
  "page": 1,
  "next_page": 2
}
```

- `value` is the related model's primary key; `label` its `display` column.
- **Scoping:** options are restricted to the caller's ownership when the
  field is scoped (the default) and the related table has the ownership
  column. Fields declared `unscoped()` serve globally (shared lookups like
  countries or plans).
- `q` filters on the display column (only for `searchable()` fields).
- `per_page` caps at 100; `next_page` is null on the last page.
- Unknown subject, unknown field, or a non-relation field → 404.

---

## Server-side enforcement (validation authority)

What the contract tells the frontend is exactly what the server enforces.
`Spawnflow\Validation\RuleResolver` resolves the same effective raw rules
(same precedence, same implied rules — both sides derive implied rules from
`Field::impliedRawRules()`) for three consumers:

1. **`Flow::validate()`** — with no explicit rules argument, sources rules
   from the subject's FieldSet + active context. Precedence: explicit
   argument → context `validation()` per field → field base rules → implied
   rules.
2. **`SpawnflowFormRequest`** — FormRequest bridge for conventional
   controllers: subclass, set `protected string $subject`, and `rules()`
   returns the resolver's output for the caller's resolved context (record
   loaded from the route's `{id}` when present, synthetic record on create).
3. **Precognition** — a request carrying a `Precognition` header makes
   `Flow::validate()` run validation only and halt the chain with
   `204 + Precognition: true` headers (or the standard 422 on failure).
   `Precognition-Validate-Only: a,b` scopes validation to the named fields.
   Pair with Laravel's `HandlePrecognitiveRequests` middleware for
   Precognition headers on error responses.

Implied relation `exists` rules are fully qualified server-side
(`exists:{table},{key}` derived from the related model), so FK integrity is
enforced without hand-written rules.

---

## Consumption guidance

- **Renderers** pick widgets from `widget`, labels from `label`, options
  from `options`, async select sources from `relation` (once `options_url`
  ships), and disable non-`editable` fields.
- **Validator generators** compile non-`serverOnly` rules to Zod (or
  equivalent); `serverOnly` rules mark the field as needing a server pass.
- **Type generators** derive TS types from `type` + `nullable` +
  `writeOnly`, and a discriminated union over `variants[].context`.
