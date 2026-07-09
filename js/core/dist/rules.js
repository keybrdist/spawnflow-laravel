import { z } from 'zod';
/**
 * Runtime compiler: structured contract rules → Zod. The client-side twin
 * of the package's ZodCompiler — both implement the mapping specified in
 * docs/schema-contract.md. serverOnly rules are never compiled; fields
 * carrying them still need a server pass (Precognition or submit).
 */
export function compileField(field) {
    const byName = new Map();
    for (const rule of field.rules) {
        if (!rule.serverOnly)
            byName.set(rule.rule, rule.params ?? []);
    }
    let schema = base(field, byName);
    const isString = schema instanceof z.ZodString;
    const isNumber = schema instanceof z.ZodNumber;
    if (isString) {
        let s = schema;
        if (field.type === 'email' || byName.has('email'))
            s = s.email();
        if (byName.has('url'))
            s = s.url();
        if (byName.has('uuid'))
            s = s.uuid();
        const regex = byName.get('regex')?.[0];
        if (typeof regex === 'string')
            s = s.regex(pcreToJs(regex));
        const [min, max] = bounds(byName);
        if (byName.has('size'))
            s = s.length(Number(byName.get('size')[0]));
        if (min !== null)
            s = s.min(min);
        else if (byName.has('required') && !byName.has('size'))
            s = s.min(1, `${field.label} is required`);
        if (max !== null)
            s = s.max(max);
        schema = s;
    }
    else if (isNumber) {
        let n = schema;
        const [min, max] = bounds(byName);
        if (byName.has('size'))
            n = n.gte(Number(byName.get('size')[0])).lte(Number(byName.get('size')[0]));
        if (min !== null)
            n = n.gte(min);
        if (max !== null)
            n = n.lte(max);
        schema = n;
    }
    // Laravel required|nullable = key must be PRESENT, value may be null —
    // required suppresses .optional() even when nullable (mirrors the PHP
    // ZodCompiler's presence semantics).
    const present = byName.has('required') || byName.has('accepted');
    if (byName.has('nullable'))
        return present ? schema.nullable() : schema.nullable().optional();
    return present ? schema : schema.optional();
}
function base(field, byName) {
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
function enumSchema(values) {
    if (values.length === 0)
        return z.never();
    if (values.every((v) => typeof v === 'string')) {
        return z.enum(values);
    }
    const literals = values.map((v) => z.literal(v));
    return literals.length === 1 ? literals[0] : z.union(literals);
}
function bounds(byName) {
    const between = byName.get('between');
    if (between)
        return [Number(between[0]), Number(between[1])];
    const min = byName.get('min')?.[0];
    const max = byName.get('max')?.[0];
    return [min === undefined ? null : Number(min), max === undefined ? null : Number(max)];
}
function pcreToJs(pattern) {
    const match = /^\/(.*)\/([a-z]*)$/s.exec(pattern);
    if (match)
        return new RegExp(match[1], match[2].replace(/[uDxX]/g, ''));
    return new RegExp(pattern);
}
/**
 * Object schema for a field list: editable fields compile; fields with a
 * `confirmed` rule gain a paired `{name}_confirmation` field and a
 * match refinement.
 */
export function compileForm(fields) {
    const shape = {};
    const confirmed = [];
    for (const field of fields) {
        if (!field.editable)
            continue;
        shape[field.name] = compileField(field);
        if (field.rules.some((r) => r.rule === 'confirmed')) {
            confirmed.push(field.name);
            shape[`${field.name}_confirmation`] = z.string().optional();
        }
    }
    let schema = z.object(shape);
    for (const name of confirmed) {
        schema = schema.superRefine((values, ctx) => {
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
export function serverOnlyFields(fields) {
    return fields
        .filter((f) => f.editable && f.rules.some((r) => r.serverOnly))
        .map((f) => f.name);
}
