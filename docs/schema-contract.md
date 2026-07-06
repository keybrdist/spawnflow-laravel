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
| `relation` | object | Relation fields only. `subject` is the registered alias of the related model (null if unregistered). `options_url` is **reserved** — it will appear when the options endpoint ships (Phase 4). |

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

**Known limitation (v1):** resolution runs through the standard chain
(`auth → resolve → ask → fields`), so it requires **ownership** of the
record and throws for contexts with zero editable fields. Non-owner
("viewer") variants are therefore unreachable via the resolved endpoint
today — use the all-variants schema client-side instead. Widening resolution
for read-only contexts is planned for the validation-authority phase.

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

## Consumption guidance

- **Renderers** pick widgets from `widget`, labels from `label`, options
  from `options`, async select sources from `relation` (once `options_url`
  ships), and disable non-`editable` fields.
- **Validator generators** compile non-`serverOnly` rules to Zod (or
  equivalent); `serverOnly` rules mark the field as needing a server pass.
- **Type generators** derive TS types from `type` + `nullable` +
  `writeOnly`, and a discriminated union over `variants[].context`.
