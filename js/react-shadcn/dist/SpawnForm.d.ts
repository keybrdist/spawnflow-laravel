import React from 'react';
import type { Schema, SpawnClient, SubmitResult } from '@spawnflow-dx/core';
import { type WidgetComponent } from './widgets';
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
export declare function SpawnForm({ schema: givenSchema, client, subject, id, context, values, onSubmit, submitLabel, widgets, hideReadOnly, }: SpawnFormProps): React.JSX.Element;
