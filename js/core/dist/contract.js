// Spawnflow schema contract v1 — see docs/schema-contract.md.
export function isResolved(schema) {
    return 'context' in schema && !('variants' in schema);
}
/**
 * Flatten a resolved schema — or one variant of a variants schema — into
 * the renderer's field list, with group membership and server verdicts
 * carried alongside.
 */
export function normalize(schema, context) {
    const groups = schema.groups ?? [];
    const groupOf = new Map();
    for (const group of groups) {
        for (const member of group.fields)
            groupOf.set(member, group.name);
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
    const variant = schema.variants.find((v) => v.context === context) ?? schema.variants[0];
    if (!variant)
        throw new Error(`No variants in schema for ${schema.resource}`);
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
