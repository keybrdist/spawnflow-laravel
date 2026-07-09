import { zodResolver } from '@hookform/resolvers/zod';
import React, { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import type { FormField, GroupDescriptor, Schema, SpawnClient, SubmitResult, Verdict } from '@spawnflow-dx/core';
import { compileForm, fieldVerdicts, normalize } from '@spawnflow-dx/core';
import { defaultWidgets, type WidgetComponent } from './widgets.js';

export interface SpawnFormProps {
  /** Pre-fetched contract schema (resolved or variants)… */
  schema?: Schema;
  /** …or let the form fetch it. */
  client?: SpawnClient;
  subject?: string;
  id?: number;
  /** Variant to render when the schema has multiple (create forms). */
  context?: string;
  /** Initial values (e.g. the record being edited). */
  values?: Record<string, unknown>;
  /** Called with validated values. Return a SubmitResult to surface server errors. */
  onSubmit?: (values: Record<string, unknown>) => Promise<SubmitResult | void> | SubmitResult | void;
  submitLabel?: string;
  /** Override or extend the widget registry (widget name → component). */
  widgets?: Record<string, WidgetComponent>;
  /** Hide read-only fields instead of rendering them disabled. */
  hideReadOnly?: boolean;
}

/**
 * Schema-driven, context-aware form. Widgets come from the field
 * descriptors, client-side validation from the structured rules (compiled
 * to Zod), editability from the resolved context. Non-editable fields
 * render disabled; write-only confirmations ('confirmed' rule) get a
 * paired input automatically.
 */
export function SpawnForm({
  schema: givenSchema,
  client,
  subject,
  id,
  context,
  values,
  onSubmit,
  submitLabel = 'Save',
  widgets,
  hideReadOnly = false,
}: SpawnFormProps) {
  const [fetched, setFetched] = useState<Schema | null>(null);
  const [fetchError, setFetchError] = useState<string | null>(null);

  useEffect(() => {
    if (givenSchema || !client || !subject) return;
    let cancelled = false;
    client
      .schema(subject, id)
      .then((s) => !cancelled && setFetched(s))
      .catch((e: Error) => !cancelled && setFetchError(e.message));
    return () => {
      cancelled = true;
    };
  }, [givenSchema, client, subject, id]);

  const schema = givenSchema ?? fetched;

  if (fetchError) return <p className="text-sm text-destructive">{fetchError}</p>;
  if (!schema) return <p className="text-sm text-muted-foreground">Loading schema…</p>;

  return (
    <ResolvedForm
      key={`${schema.resource}:${context ?? ''}:${id ?? 'new'}`}
      schema={schema}
      context={context}
      values={values}
      onSubmit={onSubmit}
      submitLabel={submitLabel}
      widgets={widgets}
      hideReadOnly={hideReadOnly}
      client={client}
      subject={subject ?? schema.resource}
      id={id}
    />
  );
}

function ResolvedForm({
  schema,
  context,
  values,
  onSubmit,
  submitLabel,
  widgets,
  hideReadOnly,
  client,
  subject,
  id,
}: Required<Pick<SpawnFormProps, 'schema' | 'submitLabel' | 'hideReadOnly'>> &
  Pick<SpawnFormProps, 'context' | 'values' | 'onSubmit' | 'widgets' | 'client' | 'id'> & { subject: string }) {
  const normalized = useMemo(() => normalize(schema!, context), [schema, context]);
  const { context: resolvedContext, fields, groups } = normalized;

  const zodSchema = useMemo(() => compileForm(fields), [fields]);
  const registry = { ...defaultWidgets, ...widgets };

  const defaults: Record<string, unknown> = {};
  for (const field of fields) {
    if (!field.editable) continue;
    defaults[field.name] = values?.[field.name] ?? field.default ?? (field.type === 'bool' ? false : undefined);
  }

  // Verdict-aware resolver: rule-ineligible fields are discarded by the
  // server, never validated — mirror that BEFORE Zod sees the values, so
  // a hidden required field cannot block submit client-side.
  const resolver = useMemo(() => {
    const base = zodResolver(zodSchema as never);
    return async (vals: Record<string, any>, ctx: unknown, options: unknown) => {
      const submitVerdicts = fieldVerdicts(normalized, vals);
      const ineligible = Object.entries(submitVerdicts)
        .filter(([, verdict]) => !verdict.visible || !verdict.enabled)
        .map(([name]) => name);

      const eligible: Record<string, any> = { ...vals };
      for (const name of ineligible) delete eligible[name];

      const result = await (base as any)(eligible, ctx, options);
      if (result.errors && ineligible.length > 0) {
        const errors = { ...result.errors };
        for (const name of ineligible) delete errors[name];
        const failed = Object.keys(errors).length > 0;
        return failed ? { values: {}, errors } : { values: eligible, errors: {} };
      }

      return result;
    };
  }, [zodSchema, normalized]);

  const form = useForm<Record<string, any>>({
    resolver: resolver as never,
    defaultValues: defaults,
    mode: 'onBlur',
  });
  const [formError, setFormError] = useState<string | null>(null);
  const [succeeded, setSucceeded] = useState(false);

  const submit = form.handleSubmit(async (data) => {
    setFormError(null);
    setSucceeded(false);
    return await onValid(data as Record<string, unknown>);
  }, () => {
    // Client validation blocked the submit — clear stale success state.
    setFormError(null);
    setSucceeded(false);
  });

  async function onValid(data: Record<string, unknown>) {
    // Mirror the server's clear-on-ineligible semantics: values of
    // rule-ineligible fields are discarded, not submitted.
    const finalVerdicts = fieldVerdicts(normalized, data);
    for (const [name, verdict] of Object.entries(finalVerdicts)) {
      if (!verdict.visible || !verdict.enabled) delete data[name];
    }

    const result = onSubmit
      ? await onSubmit(data as Record<string, unknown>)
      : client
        ? await client.submit(subject, data as Record<string, unknown>, id)
        : undefined;

    if (result && !result.ok) {
      for (const [name, messages] of Object.entries(result.errors)) {
        form.setError(name, { type: 'server', message: messages[0] });
      }
      if (result.message && Object.keys(result.errors).length === 0) setFormError(result.message);
      return;
    }
    setSucceeded(true);
  }

  // Live rule verdicts: re-evaluated as values change, mirroring the
  // server's Eligibility::fieldVerdicts(). Hidden fields unmount;
  // disabled fields render but reject input.
  const watched = form.watch();
  const verdicts: Record<string, Verdict> = fieldVerdicts(normalized, watched);

  const visible = fields
    .filter((f) => f.visible || f.editable)
    .filter((f) => !hideReadOnly || f.editable)
    .filter((f) => verdicts[f.name]?.visible !== false);

  const ungrouped = visible.filter((f) => !f.group);
  const sections = groups
    .map((group) => ({ group, members: visible.filter((f) => f.group === group.name) }))
    .filter(({ members }) => members.length > 0);

  const row = (field: FormField) => (
    <FieldRow
      key={field.name}
      field={field}
      form={form}
      registry={registry}
      client={client}
      subject={subject}
      values={values}
      disabled={verdicts[field.name]?.enabled === false}
    />
  );

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-4" data-context={resolvedContext}>
      {ungrouped.map(row)}
      {sections.map(({ group, members }) => (
        <fieldset key={group.name} className="space-y-4 rounded-lg border p-4" data-group={group.name}>
          <legend className="px-1 text-sm font-medium">{group.label}</legend>
          {members.map(row)}
        </fieldset>
      ))}
      {formError && <p className="text-sm text-destructive">{formError}</p>}
      {succeeded && <p className="text-sm text-emerald-600">Saved.</p>}
      <button
        type="submit"
        disabled={form.formState.isSubmitting || fields.every((f) => !f.editable)}
        className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 disabled:pointer-events-none disabled:opacity-50"
      >
        {form.formState.isSubmitting ? 'Saving…' : submitLabel}
      </button>
    </form>
  );
}

function FieldRow({
  field,
  form,
  registry,
  client,
  subject,
  values,
  disabled = false,
}: {
  field: FormField;
  form: ReturnType<typeof useForm>;
  registry: Record<string, WidgetComponent>;
  client?: SpawnClient;
  subject: string;
  values?: Record<string, unknown>;
  disabled?: boolean;
}) {
  const Widget = registry[field.widget] ?? registry.input;
  const confirmed = field.rules.some((r) => r.rule === 'confirmed');
  const serverOnly = field.rules.filter((r) => r.serverOnly).map((r) => r.rule);

  if (!field.editable) {
    const raw = values?.[field.name];
    return (
      <div className="space-y-1.5">
        <Label field={field} />
        <input
          className="flex h-9 w-full rounded-md border border-input bg-muted/50 px-3 py-1 text-sm text-muted-foreground"
          value={raw === undefined || raw === null ? '—' : String(raw)}
          disabled
        />
      </div>
    );
  }

  return (
    <>
      <Controller
        name={field.name as never}
        control={form.control}
        render={({ field: rhf, fieldState }) => (
          <div className="space-y-1.5">
            {field.widget !== 'checkbox' && <Label field={field} serverOnly={serverOnly} />}
            <Widget
              field={field}
              value={rhf.value}
              onChange={rhf.onChange}
              onBlur={rhf.onBlur}
              disabled={disabled}
              error={fieldState.error?.message}
              client={client}
              subject={subject}
            />
            {fieldState.error && <p className="text-sm text-destructive">{fieldState.error.message}</p>}
          </div>
        )}
      />
      {confirmed && (
        <Controller
          name={`${field.name}_confirmation` as never}
          control={form.control}
          render={({ field: rhf, fieldState }) => (
            <div className="space-y-1.5">
              <label className="text-sm font-medium leading-none">Confirm {field.label.toLowerCase()}</label>
              <input
                type="password"
                autoComplete="new-password"
                className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                value={(rhf.value as string) ?? ''}
                onChange={(e) => rhf.onChange(e.target.value)}
                onBlur={rhf.onBlur}
              />
              {fieldState.error && <p className="text-sm text-destructive">{fieldState.error.message}</p>}
            </div>
          )}
        />
      )}
    </>
  );
}

function Label({ field, serverOnly = [] }: { field: FormField; serverOnly?: string[] }) {
  const required = field.rules.some((r) => r.rule === 'required');
  return (
    <label className="flex items-center gap-1.5 text-sm font-medium leading-none">
      {field.label}
      {required && <span className="text-destructive">*</span>}
      {serverOnly.length > 0 && (
        <span
          className="rounded bg-muted px-1 py-0.5 text-[10px] font-normal text-muted-foreground"
          title={`Checked server-side: ${serverOnly.join(', ')}`}
        >
          server-checked
        </span>
      )}
    </label>
  );
}
