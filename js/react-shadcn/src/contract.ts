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
 * What the renderer needs from a backend. createHttpClient() implements it
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

/** Normalized per-field model the renderer works from. */
export type FormField = FieldDescriptor & {
  name: string;
  editable: boolean;
  visible: boolean;
  rules: StructuredRule[];
};

/**
 * Flatten a resolved schema — or one variant of a variants schema — into
 * the renderer's field list.
 */
export function normalize(schema: Schema, context?: string): { context: string; fields: FormField[] } {
  if (isResolved(schema)) {
    return {
      context: schema.context,
      fields: Object.entries(schema.fields).map(([name, field]) => ({
        name,
        ...field,
        rules: field.rules ?? [],
      })),
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
    })),
  };
}
