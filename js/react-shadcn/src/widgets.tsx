import React, { useEffect, useRef, useState } from 'react';
import type { FieldOption, FormField, SpawnClient } from './contract';

export interface WidgetProps {
  field: FormField;
  value: unknown;
  onChange: (value: unknown) => void;
  onBlur: () => void;
  disabled: boolean;
  error?: string;
  client?: SpawnClient;
  subject?: string;
}

export type WidgetComponent = React.ComponentType<WidgetProps>;

const inputClass =
  'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors ' +
  'placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring ' +
  'disabled:cursor-not-allowed disabled:opacity-50';

function TextWidget({ field, value, onChange, onBlur, disabled }: WidgetProps) {
  const type = field.type === 'email' ? 'email' : field.type === 'password' ? 'password' : 'text';
  return (
    <input
      type={type}
      className={inputClass}
      value={(value as string) ?? ''}
      onChange={(e) => onChange(e.target.value)}
      onBlur={onBlur}
      disabled={disabled}
      autoComplete={field.type === 'password' ? 'new-password' : undefined}
    />
  );
}

function NumberWidget({ value, onChange, onBlur, disabled }: WidgetProps) {
  return (
    <input
      type="number"
      className={inputClass}
      value={value === undefined || value === null ? '' : String(value)}
      onChange={(e) => onChange(e.target.value === '' ? undefined : Number(e.target.value))}
      onBlur={onBlur}
      disabled={disabled}
    />
  );
}

function TextareaWidget({ value, onChange, onBlur, disabled }: WidgetProps) {
  return (
    <textarea
      className={inputClass + ' h-auto min-h-20'}
      rows={3}
      value={(value as string) ?? ''}
      onChange={(e) => onChange(e.target.value)}
      onBlur={onBlur}
      disabled={disabled}
    />
  );
}

// Wire-aware truthiness: legacy `on_off` boolean fields deliver 'on'/'off'
// strings, and 'off' is truthy to Boolean() — map the known wire encodings
// explicitly instead. Submission stays a real boolean (the contract's boolean
// type); the server converts back to the wire format.
function isCheckedValue(value: unknown): boolean {
  if (typeof value === 'string') {
    return value === 'on' || value === '1' || value === 'true';
  }
  return value === true || value === 1;
}

function CheckboxWidget({ field, value, onChange, disabled }: WidgetProps) {
  return (
    <label className="flex items-center gap-2 text-sm">
      <input
        type="checkbox"
        className="h-4 w-4 rounded border-input accent-primary"
        checked={isCheckedValue(value)}
        onChange={(e) => onChange(e.target.checked)}
        disabled={disabled}
      />
      <span className="text-muted-foreground">{field.label}</span>
    </label>
  );
}

function SelectWidget({ field, value, onChange, onBlur, disabled }: WidgetProps) {
  return (
    <select
      className={inputClass}
      value={value === undefined || value === null ? '' : String(value)}
      onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
      onBlur={onBlur}
      disabled={disabled}
    >
      <option value="">— select —</option>
      {(field.options ?? []).map((option) => (
        <option key={String(option.value)} value={String(option.value)}>
          {option.label}
        </option>
      ))}
    </select>
  );
}

function DateWidget({ field, value, onChange, onBlur, disabled }: WidgetProps) {
  return (
    <input
      type={field.type === 'datetime' ? 'datetime-local' : 'date'}
      className={inputClass}
      value={(value as string) ?? ''}
      onChange={(e) => onChange(e.target.value)}
      onBlur={onBlur}
      disabled={disabled}
    />
  );
}

/**
 * Async searchable select for relation fields, backed by the options
 * endpoint (debounced q search, load-more pagination).
 */
function ComboboxWidget({ field, value, onChange, disabled, client, subject }: WidgetProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [options, setOptions] = useState<FieldOption[]>([]);
  const [nextPage, setNextPage] = useState<number | null>(null);
  const [selectedLabel, setSelectedLabel] = useState<string | null>(null);
  const rootRef = useRef<HTMLDivElement>(null);
  const timer = useRef<ReturnType<typeof setTimeout>>();

  const load = async (q: string, page = 1, append = false) => {
    if (!client || !subject) return;
    const result = await client.options(subject, field.name, { q: q || undefined, page });
    setOptions((prev) => (append ? [...prev, ...result.options] : result.options));
    setNextPage(result.next_page);
  };

  useEffect(() => {
    if (!open) return;
    clearTimeout(timer.current);
    timer.current = setTimeout(() => void load(query), query ? 200 : 0);
    return () => clearTimeout(timer.current);
  }, [open, query]);

  useEffect(() => {
    const close = (e: MouseEvent) => {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', close);
    return () => document.removeEventListener('mousedown', close);
  }, []);

  const current = selectedLabel ?? (value !== undefined && value !== null ? `#${value}` : null);

  return (
    <div ref={rootRef} className="relative">
      <button
        type="button"
        className={inputClass + ' justify-between text-left'}
        onClick={() => setOpen((o) => !o)}
        disabled={disabled}
      >
        <span className={current ? '' : 'text-muted-foreground'}>{current ?? 'Select…'}</span>
      </button>
      {open && (
        <div className="absolute z-10 mt-1 w-full rounded-md border border-input bg-popover p-1 shadow-md">
          {field.relation?.searchable && (
            <input
              autoFocus
              className={inputClass + ' mb-1'}
              placeholder="Search…"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
            />
          )}
          <ul className="max-h-48 overflow-auto text-sm">
            {options.length === 0 && <li className="px-2 py-1.5 text-muted-foreground">No results</li>}
            {options.map((option) => (
              <li key={String(option.value)}>
                <button
                  type="button"
                  className="w-full rounded-sm px-2 py-1.5 text-left hover:bg-accent"
                  onClick={() => {
                    onChange(option.value);
                    setSelectedLabel(option.label);
                    setOpen(false);
                  }}
                >
                  {option.label}
                </button>
              </li>
            ))}
            {nextPage !== null && (
              <li>
                <button
                  type="button"
                  className="w-full rounded-sm px-2 py-1.5 text-left text-muted-foreground hover:bg-accent"
                  onClick={() => void load(query, nextPage, true)}
                >
                  Load more…
                </button>
              </li>
            )}
          </ul>
        </div>
      )}
    </div>
  );
}

export const defaultWidgets: Record<string, WidgetComponent> = {
  input: TextWidget,
  password: TextWidget,
  textarea: TextareaWidget,
  number: NumberWidget,
  checkbox: CheckboxWidget,
  switch: CheckboxWidget,
  select: SelectWidget,
  combobox: ComboboxWidget,
  datepicker: DateWidget,
  datetimepicker: DateWidget,
};
