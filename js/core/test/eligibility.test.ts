import { describe, expect, it } from 'vitest';
import type { FormField, GroupDescriptor, Verdict } from '../src/contract';
import { fieldVerdicts, references, resolveRules } from '../src/eligibility';

const field = (name: string, extra: Partial<FormField> = {}): FormField => ({
  name,
  type: 'string',
  widget: 'input',
  label: name,
  editable: true,
  visible: true,
  rules: [],
  ...extra,
});

describe('resolveRules', () => {
  it('ANDs verdicts per axis and defaults to eligible', () => {
    const rules = [
      { effect: 'show' as const, condition: { '==': [{ var: 'type' }, 'business'] } },
      { effect: 'enable' as const, condition: { '>': [{ var: 'seats' }, 5] } },
    ];

    expect(resolveRules(rules, { type: 'business', seats: 10 })).toEqual({ visible: true, enabled: true });
    expect(resolveRules(rules, { type: 'personal', seats: 10 })).toEqual({ visible: false, enabled: true });
    expect(resolveRules(rules, { type: 'business', seats: 2 })).toEqual({ visible: true, enabled: false });
    expect(resolveRules([], {})).toEqual({ visible: true, enabled: true });
  });

  it('fails closed on evaluation errors regardless of polarity', () => {
    const broken = { unknown_op: [] };

    expect(resolveRules([{ effect: 'hide', condition: broken }], {})).toEqual({ visible: false, enabled: true });
    expect(resolveRules([{ effect: 'disable', condition: broken }], {})).toEqual({ visible: true, enabled: false });
  });
});

describe('fieldVerdicts', () => {
  const groups: GroupDescriptor[] = [
    {
      name: 'meta',
      label: 'Meta',
      fields: ['body', 'owner_id'],
      eligibility: [{ effect: 'show', condition: { '==': [{ var: 'status' }, 'draft'] } }],
    },
  ];

  const fields = [
    field('title'),
    field('status'),
    field('body', { group: 'meta' }),
    // Own passing SHOW rule — the hidden group must still win (AND).
    field('owner_id', { group: 'meta', eligibility: [{ effect: 'show', condition: true }] }),
  ];

  const form = { fields, groups, resolved: {}, resolvedGroups: {} };

  it('folds group verdicts into member fields with AND composition', () => {
    expect(fieldVerdicts(form, { status: 'draft' })).toEqual({
      body: { visible: true, enabled: true },
      owner_id: { visible: true, enabled: true },
    });

    const published = fieldVerdicts(form, { status: 'published' });
    expect(published.body).toEqual({ visible: false, enabled: true });
    expect(published.owner_id).toEqual({ visible: false, enabled: true });
    expect(published).not.toHaveProperty('title');
  });

  it('uses server-shipped verdicts for serverResolved fields and groups', () => {
    const shipped: Record<string, Verdict> = { secret: { visible: true, enabled: false } };
    const serverForm = {
      fields: [field('secret', { serverResolved: true })],
      groups: [],
      resolved: shipped,
      resolvedGroups: {},
    };

    expect(fieldVerdicts(serverForm, {})).toEqual({ secret: { visible: true, enabled: false } });
  });

  it('completes absent values from defaults then null', () => {
    const withDefault = {
      fields: [
        field('status', { default: 'draft' }),
        field('body', { eligibility: [{ effect: 'enable', condition: { '==': [{ var: 'status' }, 'draft'] } }] }),
      ],
      groups: [],
      resolved: {},
      resolvedGroups: {},
    };

    expect(fieldVerdicts(withDefault, {})).toEqual({ body: { visible: true, enabled: true } });
    expect(fieldVerdicts(withDefault, { status: 'published' })).toEqual({ body: { visible: true, enabled: false } });
  });
});

describe('references', () => {
  it('collects var and missing names, root segments, deduplicated', () => {
    expect(
      references({
        and: [
          { '==': [{ var: 'country' }, 'DE'] },
          { '>': [{ var: 'seats.min' }, 3] },
          { missing: ['country', 'vat_id'] },
          { '==': [{ var: ['optional', 'fallback'] }, 'fallback'] },
        ],
      }),
    ).toEqual(['country', 'seats', 'vat_id', 'optional']);
  });
});
