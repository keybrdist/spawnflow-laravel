import { jsx as _jsx, jsxs as _jsxs, Fragment as _Fragment } from "react/jsx-runtime";
import { zodResolver } from '@hookform/resolvers/zod';
import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { compileForm, fieldVerdicts, normalize } from '@spawnflow-dx/core';
import { defaultWidgets } from './widgets';
/**
 * Schema-driven, context-aware form. Widgets come from the field
 * descriptors, client-side validation from the structured rules (compiled
 * to Zod), editability from the resolved context. Non-editable fields
 * render disabled; write-only confirmations ('confirmed' rule) get a
 * paired input automatically.
 */
export function SpawnForm({ schema: givenSchema, client, subject, id, context, values, onSubmit, submitLabel = 'Save', widgets, hideReadOnly = false, }) {
    const [fetched, setFetched] = useState(null);
    const [fetchError, setFetchError] = useState(null);
    useEffect(() => {
        if (givenSchema || !client || !subject)
            return;
        let cancelled = false;
        client
            .schema(subject, id)
            .then((s) => !cancelled && setFetched(s))
            .catch((e) => !cancelled && setFetchError(e.message));
        return () => {
            cancelled = true;
        };
    }, [givenSchema, client, subject, id]);
    const schema = givenSchema ?? fetched;
    if (fetchError)
        return _jsx("p", { className: "text-sm text-destructive", children: fetchError });
    if (!schema)
        return _jsx("p", { className: "text-sm text-muted-foreground", children: "Loading schema\u2026" });
    return (_jsx(ResolvedForm, { schema: schema, context: context, values: values, onSubmit: onSubmit, submitLabel: submitLabel, widgets: widgets, hideReadOnly: hideReadOnly, client: client, subject: subject ?? schema.resource, id: id }, `${schema.resource}:${context ?? ''}:${id ?? 'new'}`));
}
function ResolvedForm({ schema, context, values, onSubmit, submitLabel, widgets, hideReadOnly, client, subject, id, }) {
    const normalized = useMemo(() => normalize(schema, context), [schema, context]);
    const { context: resolvedContext, fields, groups } = normalized;
    const zodSchema = useMemo(() => compileForm(fields), [fields]);
    const registry = { ...defaultWidgets, ...widgets };
    const defaults = {};
    for (const field of fields) {
        if (!field.editable)
            continue;
        defaults[field.name] = values?.[field.name] ?? field.default ?? (field.type === 'bool' ? false : undefined);
    }
    // Verdict-aware resolver: rule-ineligible fields are discarded by the
    // server, never validated — mirror that BEFORE Zod sees the values, so
    // a hidden required field cannot block submit client-side.
    const resolver = useMemo(() => {
        const base = zodResolver(zodSchema);
        return async (vals, ctx, options) => {
            const submitVerdicts = fieldVerdicts(normalized, vals);
            const ineligible = Object.entries(submitVerdicts)
                .filter(([, verdict]) => !verdict.visible || !verdict.enabled)
                .map(([name]) => name);
            const eligible = { ...vals };
            for (const name of ineligible)
                delete eligible[name];
            const result = await base(eligible, ctx, options);
            if (result.errors && ineligible.length > 0) {
                const errors = { ...result.errors };
                for (const name of ineligible)
                    delete errors[name];
                const failed = Object.keys(errors).length > 0;
                return failed ? { values: {}, errors } : { values: eligible, errors: {} };
            }
            return result;
        };
    }, [zodSchema, normalized]);
    const form = useForm({
        resolver: resolver,
        defaultValues: defaults,
        mode: 'onBlur',
    });
    const [formError, setFormError] = useState(null);
    const [succeeded, setSucceeded] = useState(false);
    const submit = form.handleSubmit(async (data) => {
        setFormError(null);
        setSucceeded(false);
        return await onValid(data);
    }, () => {
        // Client validation blocked the submit — clear stale success state.
        setFormError(null);
        setSucceeded(false);
    });
    async function onValid(data) {
        // Mirror the server's clear-on-ineligible semantics: values of
        // rule-ineligible fields are discarded, not submitted.
        const finalVerdicts = fieldVerdicts(normalized, data);
        for (const [name, verdict] of Object.entries(finalVerdicts)) {
            if (!verdict.visible || !verdict.enabled)
                delete data[name];
        }
        const result = onSubmit
            ? await onSubmit(data)
            : client
                ? await client.submit(subject, data, id)
                : undefined;
        if (result && !result.ok) {
            for (const [name, messages] of Object.entries(result.errors)) {
                form.setError(name, { type: 'server', message: messages[0] });
            }
            if (result.message && Object.keys(result.errors).length === 0)
                setFormError(result.message);
            return;
        }
        setSucceeded(true);
    }
    // Live rule verdicts: re-evaluated as values change, mirroring the
    // server's Eligibility::fieldVerdicts(). Hidden fields unmount;
    // disabled fields render but reject input.
    const watched = form.watch();
    const verdicts = fieldVerdicts(normalized, watched);
    const visible = fields
        .filter((f) => f.visible || f.editable)
        .filter((f) => !hideReadOnly || f.editable)
        .filter((f) => verdicts[f.name]?.visible !== false);
    const ungrouped = visible.filter((f) => !f.group);
    const sections = groups
        .map((group) => ({ group, members: visible.filter((f) => f.group === group.name) }))
        .filter(({ members }) => members.length > 0);
    const row = (field) => (_jsx(FieldRow, { field: field, form: form, registry: registry, client: client, subject: subject, values: values, disabled: verdicts[field.name]?.enabled === false }, field.name));
    return (_jsxs("form", { onSubmit: (e) => void submit(e), className: "space-y-4", "data-context": resolvedContext, children: [ungrouped.map(row), sections.map(({ group, members }) => (_jsxs("fieldset", { className: "space-y-4 rounded-lg border p-4", "data-group": group.name, children: [_jsx("legend", { className: "px-1 text-sm font-medium", children: group.label }), members.map(row)] }, group.name))), formError && _jsx("p", { className: "text-sm text-destructive", children: formError }), succeeded && _jsx("p", { className: "text-sm text-emerald-600", children: "Saved." }), _jsx("button", { type: "submit", disabled: form.formState.isSubmitting || fields.every((f) => !f.editable), className: "inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50", children: form.formState.isSubmitting ? 'Saving…' : submitLabel })] }));
}
function FieldRow({ field, form, registry, client, subject, values, disabled = false, }) {
    const Widget = registry[field.widget] ?? registry.input;
    const confirmed = field.rules.some((r) => r.rule === 'confirmed');
    const serverOnly = field.rules.filter((r) => r.serverOnly).map((r) => r.rule);
    if (!field.editable) {
        const raw = values?.[field.name];
        return (_jsxs("div", { className: "space-y-1.5", children: [_jsx(Label, { field: field }), _jsx("input", { className: "flex h-9 w-full rounded-md border border-input bg-muted/50 px-3 py-1 text-sm text-muted-foreground", value: raw === undefined || raw === null ? '—' : String(raw), disabled: true })] }));
    }
    return (_jsxs(_Fragment, { children: [_jsx(Controller, { name: field.name, control: form.control, render: ({ field: rhf, fieldState }) => (_jsxs("div", { className: "space-y-1.5", children: [field.widget !== 'checkbox' && _jsx(Label, { field: field, serverOnly: serverOnly }), _jsx(Widget, { field: field, value: rhf.value, onChange: rhf.onChange, onBlur: rhf.onBlur, disabled: disabled, error: fieldState.error?.message, client: client, subject: subject }), fieldState.error && _jsx("p", { className: "text-sm text-destructive", children: fieldState.error.message })] })) }), confirmed && (_jsx(Controller, { name: `${field.name}_confirmation`, control: form.control, render: ({ field: rhf, fieldState }) => (_jsxs("div", { className: "space-y-1.5", children: [_jsxs("label", { className: "text-sm font-medium leading-none", children: ["Confirm ", field.label.toLowerCase()] }), _jsx("input", { type: "password", autoComplete: "new-password", className: "flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring", value: rhf.value ?? '', onChange: (e) => rhf.onChange(e.target.value), onBlur: rhf.onBlur }), fieldState.error && _jsx("p", { className: "text-sm text-destructive", children: fieldState.error.message })] })) }))] }));
}
function Label({ field, serverOnly = [] }) {
    const required = field.rules.some((r) => r.rule === 'required');
    return (_jsxs("label", { className: "flex items-center gap-1.5 text-sm font-medium leading-none", children: [field.label, required && _jsx("span", { className: "text-destructive", children: "*" }), serverOnly.length > 0 && (_jsx("span", { className: "rounded bg-muted px-1 py-0.5 text-[10px] font-normal text-muted-foreground", title: `Checked server-side: ${serverOnly.join(', ')}`, children: "server-checked" }))] }));
}
