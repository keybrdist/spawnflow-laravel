import type {
  OptionsPage,
  ResolvedField,
  ResolvedSchema,
  Schema,
  SpawnClient,
  StructuredRule,
  SubmitResult,
} from '@spawnflow-dx/react-shadcn';

/**
 * In-memory SpawnClient serving contract-v1 schemas — what a real Laravel
 * backend with Spawnflow's schema + options routes would return. Swap for
 * createHttpClient({ baseUrl }) against a live API.
 */

export type Persona = 'ana-active' | 'ana-pastdue' | 'sam';

export const personas: Record<Persona, { name: string; note: string }> = {
  'ana-active': { name: 'Ana (owner, account active)', note: 'Owns the record; billing in good standing.' },
  'ana-pastdue': { name: 'Ana (owner, past due)', note: 'Same owner — but the account state locks most billing fields.' },
  sam: { name: 'Sam (viewer)', note: 'Not the owner. Same endpoint, read-only variant.' },
};

const r = (rule: string, params?: (string | number)[], serverOnly?: boolean): StructuredRule => ({
  rule,
  ...(params ? { params } : {}),
  ...(serverOnly ? { serverOnly: true } : {}),
});

const f = (partial: Partial<ResolvedField> & Pick<ResolvedField, 'type' | 'widget' | 'label'>): ResolvedField => ({
  editable: true,
  visible: true,
  rules: [],
  ...partial,
});

// ---------------------------------------------------------------
// Records (per persona where contexts differ)
// ---------------------------------------------------------------

export const records = {
  profile: {
    display_name: 'Ana Martins',
    bio: 'Distribution + royalties. Coffee-driven.',
    timezone: 'Europe/Lisbon',
    team_id: 2,
    visibility: 'public',
    verified_badge: 'yes',
  },
  billing: {
    account_type: 'business',
    billing_email: 'billing@example.com',
    country: 'PT',
    vat_id: 'PT123456789',
    plan_id: 2,
    card_last4: '4242',
  },
};

// ---------------------------------------------------------------
// Schemas
// ---------------------------------------------------------------

const registration: ResolvedSchema = {
  spawnflow: '1',
  resource: 'users',
  context: 'guest',
  fields: {
    name: f({ type: 'string', widget: 'input', label: 'Name', rules: [r('required'), r('string'), r('max', [80])] }),
    email: f({
      type: 'email',
      widget: 'input',
      label: 'Email',
      rules: [r('required'), r('email'), r('unique', ['users', 'email'], true)],
    }),
    password: f({
      type: 'password',
      widget: 'password',
      label: 'Password',
      writeOnly: true,
      rules: [r('required'), r('min', [12]), r('confirmed')],
    }),
  },
};

const changePassword: ResolvedSchema = {
  spawnflow: '1',
  resource: 'password',
  context: 'default',
  fields: {
    current_password: f({
      type: 'password',
      widget: 'password',
      label: 'Current password',
      writeOnly: true,
      rules: [r('required'), r('current_password', undefined, true)],
    }),
    password: f({
      type: 'password',
      widget: 'password',
      label: 'New password',
      writeOnly: true,
      rules: [r('required'), r('min', [12]), r('confirmed')],
    }),
  },
};

const timezoneOptions = ['Europe/Lisbon', 'Europe/Berlin', 'America/Los_Angeles', 'America/New_York', 'Asia/Tokyo'];

function profileSchema(persona: Persona): ResolvedSchema {
  const verified = persona !== 'sam';
  const locked: Partial<ResolvedField> = verified ? {} : { editable: false };

  return {
    spawnflow: '1',
    resource: 'profiles',
    context: verified ? 'owner:verified' : 'owner:unverified',
    fields: {
      display_name: f({
        type: 'string', widget: 'input', label: 'Display name',
        rules: [r('required'), r('string'), r('max', [40])],
      }),
      bio: f({
        type: 'text', widget: 'textarea', label: 'Bio',
        rules: [r('nullable'), r('string'), r('max', [280])],
        nullable: true,
        ...locked,
      }),
      timezone: f({
        type: 'enum', widget: 'select', label: 'Timezone',
        options: timezoneOptions.map((tz) => ({ value: tz, label: tz })),
        rules: [r('required'), r('in', timezoneOptions)],
      }),
      team_id: f({
        type: 'relation', widget: 'combobox', label: 'Team',
        relation: { subject: 'teams', display: 'name', searchable: true, multiple: false, options_url: '/spawnflow/options/profiles/team_id' },
        rules: [r('required'), r('exists', ['teams', 'id'], true)],
        ...locked,
      }),
      visibility: f({
        type: 'enum', widget: 'select', label: 'Profile visibility',
        options: [
          { value: 'public', label: 'Public' },
          { value: 'unlisted', label: 'Unlisted' },
          { value: 'private', label: 'Private' },
        ],
        rules: [r('required'), r('in', ['public', 'unlisted', 'private'])],
      }),
      verified_badge: f({ type: 'string', widget: 'input', label: 'Verified badge', editable: false }),
    },
  };
}

const countries = [
  { value: 'PT', label: 'Portugal' },
  { value: 'DE', label: 'Germany' },
  { value: 'GB', label: 'United Kingdom' },
  { value: 'US', label: 'United States' },
];

function billingSchema(persona: Persona): ResolvedSchema {
  const context = persona === 'sam' ? 'viewer' : persona === 'ana-pastdue' ? 'owner:past_due' : 'owner:active';
  const full = context === 'owner:active';
  const owner = context !== 'viewer';
  const ifFull: Partial<ResolvedField> = full ? {} : { editable: false };
  const ifOwner: Partial<ResolvedField> = owner ? {} : { editable: false };

  return {
    spawnflow: '1',
    resource: 'billing',
    context,
    fields: {
      account_type: f({
        type: 'enum', widget: 'select', label: 'Account type',
        options: [
          { value: 'personal', label: 'Personal' },
          { value: 'business', label: 'Business' },
        ],
        default: 'personal',
        rules: [r('required'), r('in', ['personal', 'business'])],
        ...ifFull,
      }),
      billing_email: f({
        type: 'email', widget: 'input', label: 'Billing email',
        rules: [r('required'), r('email')],
        ...ifOwner,
      }),
      country: f({
        type: 'enum', widget: 'select', label: 'Country',
        options: countries,
        rules: [r('required'), r('in', countries.map((c) => c.value))],
        ...ifFull,
      }),
      vat_id: f({
        type: 'string', widget: 'input', label: 'VAT ID',
        nullable: true,
        rules: [r('nullable'), r('string'), r('vat_format', undefined, true)],
        ...ifFull,
      }),
      plan_id: f({
        type: 'relation', widget: 'combobox', label: 'Plan',
        relation: { subject: 'plans', display: 'name', searchable: false, multiple: false, options_url: '/spawnflow/options/billing/plan_id' },
        rules: [r('required'), r('exists', ['plans', 'id'], true)],
        ...ifOwner,
      }),
      card_last4: f({ type: 'string', widget: 'input', label: 'Card (last 4)', editable: false }),
    },
    // Live eligibility: the tax section only exists for business
    // accounts. The renderer re-evaluates as the select changes; the
    // server enforces the same rule on save (clear-on-ineligible).
    groups: [
      {
        name: 'tax',
        label: 'Tax details',
        fields: ['country', 'vat_id'],
        eligibility: [
          { effect: 'show', condition: { '==': [{ var: 'account_type' }, 'business'] } },
        ],
      },
    ],
    resolved: {
      country: { visible: true, enabled: true },
      vat_id: { visible: true, enabled: true },
    },
    resolved_groups: {
      tax: { visible: true, enabled: true },
    },
  };
}

// ---------------------------------------------------------------
// Option sources
// ---------------------------------------------------------------

const teams = ['Design', 'Engineering', 'Growth', 'Marketing', 'Ops', 'Sales', 'Support']
  .map((name, i) => ({ value: i + 1, label: name }));

const plans = [
  { value: 1, label: 'Starter — $9/mo' },
  { value: 2, label: 'Pro — $29/mo' },
  { value: 3, label: 'Scale — $99/mo' },
];

// ---------------------------------------------------------------
// Client
// ---------------------------------------------------------------

const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

export function createMockClient(persona: Persona): SpawnClient {
  return {
    async schema(subject): Promise<Schema> {
      await delay(150);
      switch (subject) {
        case 'users': return registration;
        case 'password': return changePassword;
        case 'profiles': return profileSchema(persona);
        case 'billing': return billingSchema(persona);
        default: throw new Error(`Unknown subject: ${subject}`);
      }
    },

    async options(_subject, field, params = {}): Promise<OptionsPage> {
      await delay(200);
      const source = field === 'plan_id' ? plans : teams;
      const q = (params.q ?? '').toLowerCase();
      const filtered = q ? source.filter((o) => o.label.toLowerCase().includes(q)) : source;
      const perPage = 5;
      const page = params.page ?? 1;
      const slice = filtered.slice((page - 1) * perPage, page * perPage + 1);
      return {
        options: slice.slice(0, perPage),
        page,
        next_page: slice.length > perPage ? page + 1 : null,
      };
    },

    async submit(subject, values): Promise<SubmitResult> {
      await delay(350);

      // The serverOnly rules the schema was honest about, enforced here —
      // exactly what a real backend's validation pass would catch.
      if (subject === 'users' && values.email === 'taken@example.com') {
        return { ok: false, errors: { email: ['That email is already registered. (serverOnly: unique)'] } };
      }
      if (subject === 'password' && values.current_password !== 'password123') {
        return { ok: false, errors: { current_password: ['Current password is incorrect. (serverOnly: current_password)'] } };
      }
      if (subject === 'billing' && values.vat_id && !/^[A-Z]{2}[0-9A-Z]{8,12}$/.test(String(values.vat_id))) {
        return { ok: false, errors: { vat_id: ['VAT ID format is invalid for the selected country. (serverOnly: vat_format)'] } };
      }

      return { ok: true, data: values };
    },
  };
}
