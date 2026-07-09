import { SpawnForm } from '@spawnflow-dx/react-shadcn';
import React, { useMemo, useState } from 'react';
import { createMockClient, personas, records, type Persona } from './mockApi';

type Tab = 'register' | 'password' | 'profile' | 'billing';

const tabs: { id: Tab; label: string; blurb: string }[] = [
  { id: 'register', label: 'Registration', blurb: 'Create flow. Zod handles required/email/min instantly; the unique-email check is flagged server-checked and enforced on submit (try taken@example.com).' },
  { id: 'password', label: 'Change password', blurb: 'Non-CRUD form on the same machinery. current_password is server-only (correct value: password123); the confirmed rule pairs a confirmation input automatically.' },
  { id: 'profile', label: 'Edit profile', blurb: 'Update flow with enum selects and an async searchable team combobox fed by the options endpoint. Sam’s unverified context locks bio and team.' },
  { id: 'billing', label: 'Billing details', blurb: 'The context showstopper: one component, three variants (switch personas) — PLUS live eligibility: flip Account type to Personal and the Tax details section vanishes; the server enforces the same rule on save.' },
];

export default function App() {
  const [tab, setTab] = useState<Tab>('register');
  const [persona, setPersona] = useState<Persona>('ana-active');

  const client = useMemo(() => createMockClient(persona), [persona]);

  const form = {
    register: <SpawnForm key={`r-${persona}`} client={client} subject="users" submitLabel="Create account" />,
    password: <SpawnForm key={`pw-${persona}`} client={client} subject="password" submitLabel="Update password" />,
    profile: (
      <SpawnForm key={`pf-${persona}`} client={client} subject="profiles" id={1} values={records.profile} submitLabel="Save profile" />
    ),
    billing: (
      <SpawnForm key={`b-${persona}`} client={client} subject="billing" id={1} values={records.billing} submitLabel="Save billing details" />
    ),
  }[tab];

  const active = tabs.find((t) => t.id === tab)!;
  const personaMatters = tab === 'profile' || tab === 'billing';

  return (
    <div className="mx-auto max-w-3xl px-6 py-10">
      <header className="mb-8">
        <h1 className="text-2xl font-semibold tracking-tight">Spawnflow forms</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          Every form below renders from a backend schema — types, widgets, enum options, FK selects, validation, and
          per-context editability from one declaration. No hand-written form code.
        </p>
      </header>

      <div className="mb-6 flex flex-wrap gap-1 rounded-lg bg-muted p-1">
        {tabs.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={
              'rounded-md px-3 py-1.5 text-sm font-medium transition-colors ' +
              (tab === t.id ? 'bg-background shadow-sm' : 'text-muted-foreground hover:text-foreground')
            }
          >
            {t.label}
          </button>
        ))}
      </div>

      <div className="mb-6 rounded-lg border border-border p-4">
        <label className="text-sm font-medium">Viewing as</label>
        <select
          className="mt-1.5 flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm"
          value={persona}
          onChange={(e) => setPersona(e.target.value as Persona)}
        >
          {Object.entries(personas).map(([id, p]) => (
            <option key={id} value={id}>{p.name}</option>
          ))}
        </select>
        <p className="mt-1.5 text-xs text-muted-foreground">
          {personas[persona].note}
          {!personaMatters && ' (Persona affects the profile and billing forms.)'}
        </p>
      </div>

      <div className="rounded-lg border border-border p-6">
        <p className="mb-5 text-xs leading-relaxed text-muted-foreground">{active.blurb}</p>
        {form}
      </div>
    </div>
  );
}
