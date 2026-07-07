// Spawnflow schema contract v1 — see docs/schema-contract.md.

export type StructuredRule = {
  rule: string;
  params?: (string | number)[];
  serverOnly?: boolean;
};

export type FieldOption = { value: string | number; label: string };

export type RelationMeta = {
  subject: string | null;
  display: string;
  searchable: boolean;
  multiple: boolean;
  options_url?: string;
};

/** {effect, condition} envelope — see the Eligibility section of the contract. */
export type EligibilityRule = {
  effect: 'show' | 'hide' | 'enable' | 'disable';
  condition: Condition;
};

/** Restricted JSON Logic condition (fixed operator allowlist). */
export type Condition = boolean | { [operator: string]: unknown };

export type Verdict = { visible: boolean; enabled: boolean };

export type GroupDescriptor = {
  name: string;
  label: string;
  fields: string[];
  eligibility?: EligibilityRule[];
  serverResolved?: boolean;
};

export type FieldDescriptor = {
  type:
    | 'string' | 'text' | 'int' | 'float' | 'bool' | 'date' | 'datetime'
    | 'email' | 'password' | 'enum' | 'relation' | 'json' | 'file';
  widget: string;
  label: string;
  nullable?: boolean;
  default?: unknown;
  wire?: string;
  writeOnly?: boolean;
  options?: FieldOption[];
  relation?: RelationMeta;
  eligibility?: EligibilityRule[];
  serverResolved?: boolean;
};

export type ResolvedField = FieldDescriptor & {
  editable: boolean;
  visible: boolean;
  rules?: StructuredRule[];
};

export type ResolvedSchema = {
  spawnflow: string;
  resource: string;
  context: string;
  fields: Record<string, ResolvedField>;
  groups?: GroupDescriptor[];
  resolved?: Record<string, Verdict>;
  resolved_groups?: Record<string, Verdict>;
};

export type Variant = {
  context: string;
  editable_fields: string[];
  visible_fields: string[];
  rules: Record<string, StructuredRule[]>;
};

export type VariantsSchema = {
  spawnflow: string;
  resource: string;
  fields: Record<string, FieldDescriptor>;
  variants: Variant[];
  groups?: GroupDescriptor[];
  resolved?: Record<string, Verdict>;
  resolved_groups?: Record<string, Verdict>;
};

export type Schema = ResolvedSchema | VariantsSchema;

export type OptionsPage = {
  options: FieldOption[];
  page: number;
  next_page: number | null;
};

export type SubmitResult =
  | { ok: true; data?: unknown }
  | { ok: false; errors: Record<string, string[]>; message?: string };

/**
 * What a renderer needs from a backend. createHttpClient() implements it
 * over fetch for real Spawnflow routes; tests and demos can supply mocks.
 */
export interface SpawnClient {
  schema(subject: string, id?: number): Promise<Schema>;
  options(subject: string, field: string, params?: { q?: string; page?: number }): Promise<OptionsPage>;
  submit(subject: string, values: Record<string, unknown>, id?: number): Promise<SubmitResult>;
}

export function isResolved(schema: Schema): schema is ResolvedSchema {
  return 'context' in schema && !('variants' in schema);
}

/** Normalized per-field model renderers work from. */
export type FormField = FieldDescriptor & {
  name: string;
  editable: boolean;
  visible: boolean;
  rules: StructuredRule[];
  /** Owning group name, when the schema declares groups. */
  group?: string;
};

export type NormalizedForm = {
  context: string;
  fields: FormField[];
  groups: GroupDescriptor[];
  /** Server-computed rule verdicts (record values, or defaults on create). */
  resolved: Record<string, Verdict>;
  resolvedGroups: Record<string, Verdict>;
};

/**
 * Flatten a resolved schema — or one variant of a variants schema — into
 * the renderer's field list, with group membership and server verdicts
 * carried alongside.
 */
export function normalize(schema: Schema, context?: string): NormalizedForm {
  const groups = schema.groups ?? [];
  const groupOf = new Map<string, string>();
  for (const group of groups) {
    for (const member of group.fields) groupOf.set(member, group.name);
  }

  const shared = {
    groups,
    resolved: schema.resolved ?? {},
    resolvedGroups: schema.resolved_groups ?? {},
  };

  if (isResolved(schema)) {
    return {
      context: schema.context,
      fields: Object.entries(schema.fields).map(([name, field]) => ({
        name,
        ...field,
        rules: field.rules ?? [],
        group: groupOf.get(name),
      })),
      ...shared,
    };
  }

  const variant =
    schema.variants.find((v) => v.context === context) ?? schema.variants[0];
  if (!variant) throw new Error(`No variants in schema for ${schema.resource}`);

  const names = [...new Set([...variant.editable_fields, ...variant.visible_fields])];

  return {
    context: variant.context,
    fields: names.map((name) => ({
      name,
      ...(schema.fields[name] ?? { type: 'string', widget: 'input', label: name }),
      editable: variant.editable_fields.includes(name),
      visible: variant.visible_fields.includes(name),
      rules: variant.rules[name] ?? [],
      group: groupOf.get(name),
    })),
    ...shared,
  };
}
