import { z } from 'zod';
import type { FormField, StructuredRule } from './contract';

/**
 * Runtime compiler: structured contract rules → Zod. The client-side twin
 * of the package's ZodCompiler — both implement the mapping specified in
 * docs/schema-contract.md. serverOnly rules are never compiled; fields
 * carrying them still need a server pass (Precognition or submit).
 */
export function compileField(field: FormField): z.ZodTypeAny {
  const byName = new Map<string, (string | number)[]>();
  for (const rule of field.rules) {
    if (!rule.serverOnly) byName.set(rule.rule, rule.params ?? []);
  }

  let schema = base(field, byName);

  const isString = schema instanceof z.ZodString;
  const isNumber = schema instanceof z.ZodNumber;

  if (isString) {
    let s = schema as z.ZodString;
    if (field.type === 'email' || byName.has('email')) s = s.email();
    if (byName.has('url')) s = s.url();
    if (byName.has('uuid')) s = s.uuid();
    const regex = byName.get('regex')?.[0];
    if (typeof regex === 'string') s = s.regex(pcreToJs(regex));
    const [min, max] = bounds(byName);
    if (byName.has('size')) s = s.length(Number(byName.get('size')![0]));
    if (min !== null) s = s.min(min);
    else if (byName.has('required') && !byName.has('size')) s = s.min(1, `${field.label} is required`);
    if (max !== null) s = s.max(max);
    schema = s;
  } else if (isNumber) {
    let n = schema as z.ZodNumber;
    const [min, max] = bounds(byName);
    if (byName.has('size')) n = n.gte(Number(byName.get('size')![0])).lte(Number(byName.get('size')![0]));
    if (min !== null) n = n.gte(min);
    if (max !== null) n = n.lte(max);
    schema = n;
  }

  if (byName.has('nullable')) return schema.nullable().optional();
  if (!byName.has('required') && !byName.has('accepted')) return schema.optional();

  return schema;
}

function base(field: FormField, byName: Map<string, (string | number)[]>): z.ZodTypeAny {
  switch (field.type) {
    case 'enum':
      return enumSchema((field.options ?? []).map((o) => o.value));
    case 'relation':
      return field.relation?.multiple ? z.array(z.coerce.number()) : z.coerce.number({ message: `${field.label} is required` });
    case 'int':
      return z.coerce.number().int();
    case 'float':
      return z.coerce.number();
    case 'bool':
      return byName.has('accepted')
        ? z.literal(true, { message: `${field.label} must be accepted` })
        : z.coerce.boolean();
    case 'json':
    case 'file':
      return z.unknown();
    default: {
      const inParams = byName.get('in');
      if (inParams && inParams.every((v) => typeof v === 'string')) {
        return enumSchema(inParams);
      }
      return z.string({ message: `${field.label} is required` });
    }
  }
}

function enumSchema(values: (string | number)[]): z.ZodTypeAny {
  if (values.length === 0) return z.never();
  if (values.every((v) => typeof v === 'string')) {
    return z.enum(values as [string, ...string[]]);
  }
  const literals = values.map((v) => z.literal(v));
  return literals.length === 1 ? literals[0] : z.union(literals as unknown as [z.ZodTypeAny, z.ZodTypeAny, ...z.ZodTypeAny[]]);
}

function bounds(byName: Map<string, (string | number)[]>): [number | null, number | null] {
  const between = byName.get('between');
  if (between) return [Number(between[0]), Number(between[1])];
  const min = byName.get('min')?.[0];
  const max = byName.get('max')?.[0];
  return [min === undefined ? null : Number(min), max === undefined ? null : Number(max)];
}

function pcreToJs(pattern: string): RegExp {
  const match = /^\/(.*)\/([a-z]*)$/s.exec(pattern);
  if (match) return new RegExp(match[1], match[2].replace(/[uDxX]/g, ''));
  return new RegExp(pattern);
}

/**
 * Object schema for a field list: editable fields compile; fields with a
 * `confirmed` rule gain a paired `{name}_confirmation` field and a
 * match refinement.
 */
export function compileForm(fields: FormField[]): z.ZodTypeAny {
  const shape: Record<string, z.ZodTypeAny> = {};
  const confirmed: string[] = [];

  for (const field of fields) {
    if (!field.editable) continue;
    shape[field.name] = compileField(field);
    if (field.rules.some((r) => r.rule === 'confirmed')) {
      confirmed.push(field.name);
      shape[`${field.name}_confirmation`] = z.string().optional();
    }
  }

  let schema: z.ZodTypeAny = z.object(shape);

  for (const name of confirmed) {
    schema = schema.superRefine((values: Record<string, unknown>, ctx) => {
      if (values[name] !== undefined && values[name] !== values[`${name}_confirmation`]) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: [`${name}_confirmation`],
          message: 'Confirmation does not match',
        });
      }
    });
  }

  return schema;
}

/** Field names carrying rules only the server can check. */
export function serverOnlyFields(fields: FormField[]): string[] {
  return fields
    .filter((f) => f.editable && f.rules.some((r) => r.serverOnly))
    .map((f) => f.name);
}

export type { StructuredRule };
