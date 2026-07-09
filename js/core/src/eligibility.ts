// The JS twin of Spawnflow\Eligibility (PHP). Both implement the
// restricted JSON Logic subset specified in docs/schema-contract.md;
// shared behavior is pinned by resources/conformance/eligibility-fixtures.json,
// which both test suites run. Change semantics THERE first.

import type {
  Condition,
  EligibilityRule,
  FormField,
  GroupDescriptor,
  Verdict,
} from './contract.js';

export class InvalidConditionError extends Error {}

const MISSING = Symbol('spawnflow.missing');

type Data = Record<string, unknown>;

/**
 * Explicit truthiness, identical in both runtimes: null/false falsy,
 * numbers falsy at zero, strings falsy when empty ("0" IS truthy),
 * arrays/objects falsy when empty.
 */
export function truthy(value: unknown): boolean {
  if (value === null || value === undefined) return false;
  if (typeof value === 'boolean') return value;
  if (typeof value === 'number') return value !== 0;
  if (typeof value === 'string') return value !== '';
  if (Array.isArray(value)) return value.length > 0;
  if (typeof value === 'object') return Object.keys(value as object).length > 0;
  throw new InvalidConditionError(`Cannot coerce value of type ${typeof value} to boolean`);
}

/** Whether the condition passes against the given data. Throws InvalidConditionError. */
export function passes(condition: Condition, data: Data): boolean {
  return truthy(evaluate(condition, data));
}

/** Field names referenced by a condition's var/missing nodes (root segments, deduplicated). */
export function references(condition: Condition): string[] {
  const refs: string[] = [];
  collectReferences(condition, refs);
  return [...new Set(refs)];
}

function evaluate(node: unknown, data: Data): unknown {
  if (node === null || typeof node !== 'object') return node;

  if (Array.isArray(node)) return node.map((item) => evaluate(item, data));

  const keys = Object.keys(node as object);
  if (keys.length !== 1) {
    throw new InvalidConditionError(`Operator nodes must have exactly one key, got: ${keys.join(', ')}`);
  }

  const operator = keys[0];
  const args = (node as Record<string, unknown>)[operator];

  switch (operator) {
    case 'var':
      return evaluateVar(args, data);
    case 'missing':
      return evaluateMissing(args, data);
    case '==':
      return binary(args, data, equals);
    case '!=':
      return binary(args, data, (a, b) => !equals(a, b));
    case '>':
      return numeric(args, data, (a, b) => a > b);
    case '<':
      return numeric(args, data, (a, b) => a < b);
    case '>=':
      return numeric(args, data, (a, b) => a >= b);
    case '<=':
      return numeric(args, data, (a, b) => a <= b);
    case 'and':
      return logical(args, data, true);
    case 'or':
      return logical(args, data, false);
    case '!': {
      const arg = Array.isArray(args) && args.length === 1 ? args[0] : args;
      return !truthy(evaluate(arg, data));
    }
    case 'in':
      return evaluateIn(args, data);
    default:
      throw new InvalidConditionError(`Unknown operator: ${operator}`);
  }
}

/**
 * Strict equality with the numeric carve-out mirrored from PHP: numbers
 * compare by value (int 1 equals float 1.0 there; JS has one number
 * type so === already does this). Cross-type ("1" vs 1) stays false.
 */
function equals(a: unknown, b: unknown): boolean {
  return a === b;
}

function evaluateVar(args: unknown, data: Data): unknown {
  const [path, fallback] = Array.isArray(args)
    ? [args[0], args.length > 1 ? args[1] : MISSING]
    : [args, MISSING];

  if (typeof path !== 'string' || path === '') {
    throw new InvalidConditionError('var expects a non-empty string path');
  }

  const value = extract(path, data);

  if (value === MISSING) {
    if (fallback === MISSING) throw new InvalidConditionError(`var references absent key: ${path}`);
    return fallback;
  }

  return value;
}

function evaluateMissing(args: unknown, data: Data): string[] {
  const names = Array.isArray(args) ? args : [args];

  const absent: string[] = [];
  for (const name of names) {
    if (typeof name !== 'string') throw new InvalidConditionError('missing expects string field names');
    if (extract(name, data) === MISSING) absent.push(name);
  }

  return absent;
}

function evaluateIn(args: unknown, data: Data): boolean {
  const [needle, haystack] = operands(args, data, 'in');

  if (Array.isArray(haystack)) return haystack.some((candidate) => equals(needle, candidate));

  if (typeof haystack === 'string' && typeof needle === 'string') {
    return needle !== '' && haystack.includes(needle);
  }

  throw new InvalidConditionError('in expects an array haystack, or string needle and haystack');
}

function binary(args: unknown, data: Data, compare: (a: unknown, b: unknown) => boolean): boolean {
  const [a, b] = operands(args, data, 'comparison');
  return compare(a, b);
}

function numeric(args: unknown, data: Data, compare: (a: number, b: number) => boolean): boolean {
  const [a, b] = operands(args, data, 'numeric comparison');

  if (typeof a !== 'number' || typeof b !== 'number') {
    throw new InvalidConditionError(`Numeric comparison requires numbers, got ${typeof a} and ${typeof b}`);
  }

  return compare(a, b);
}

function logical(args: unknown, data: Data, requireAll: boolean): boolean {
  if (!Array.isArray(args) || args.length === 0) {
    throw new InvalidConditionError('and/or expect a non-empty list of conditions');
  }

  for (const arg of args) {
    const isTruthy = truthy(evaluate(arg, data));
    if (requireAll && !isTruthy) return false;
    if (!requireAll && isTruthy) return true;
  }

  return requireAll;
}

function operands(args: unknown, data: Data, operator: string): [unknown, unknown] {
  if (!Array.isArray(args) || args.length !== 2) {
    throw new InvalidConditionError(`${operator} expects exactly two operands`);
  }

  return [evaluate(args[0], data), evaluate(args[1], data)];
}

/** Dot-path lookup distinguishing "absent" from "present but null/undefined". */
function extract(path: string, data: Data): unknown {
  let current: unknown = data;
  for (const segment of path.split('.')) {
    if (
      current === null ||
      typeof current !== 'object' ||
      Array.isArray(current) ||
      !(segment in (current as object))
    ) {
      return MISSING;
    }
    current = (current as Record<string, unknown>)[segment];
  }
  return current;
}

function collectReferences(node: unknown, refs: string[]): void {
  if (node === null || typeof node !== 'object') return;

  if (Array.isArray(node)) {
    for (const child of node) collectReferences(child, refs);
    return;
  }

  const keys = Object.keys(node as object);
  if (keys.length === 1) {
    const operator = keys[0];
    const args = (node as Record<string, unknown>)[operator];

    if (operator === 'var') {
      const path = Array.isArray(args) ? args[0] : args;
      if (typeof path === 'string' && path !== '') refs.push(path.split('.')[0]);
      return;
    }

    if (operator === 'missing') {
      for (const name of Array.isArray(args) ? args : [args]) {
        if (typeof name === 'string') refs.push(name.split('.')[0]);
      }
      return;
    }

    collectReferences(args, refs);
    return;
  }

  for (const key of keys) collectReferences((node as Record<string, unknown>)[key], refs);
}

// ---------------------------------------------------------------
// Rule envelopes → verdicts
// ---------------------------------------------------------------

/**
 * One envelope's verdict on its axis, or null when it governs the other.
 * Evaluation errors fail CLOSED regardless of polarity.
 */
function envelopeOutcome(rule: EligibilityRule, data: Data, axis: 'visible' | 'enabled'): boolean | null {
  const governsVisibility = rule.effect === 'show' || rule.effect === 'hide';
  if ((axis === 'visible') !== governsVisibility) return null;

  const positive = rule.effect === 'show' || rule.effect === 'enable';

  let result: boolean;
  try {
    result = passes(rule.condition, data);
  } catch {
    return false;
  }

  return positive ? result : !result;
}

/** Per-axis verdict for a set of envelopes: AND per axis, default eligible. */
export function resolveRules(rules: EligibilityRule[], data: Data): Verdict {
  let visible = true;
  let enabled = true;

  for (const rule of rules) {
    visible = visible && (envelopeOutcome(rule, data, 'visible') ?? true);
    enabled = enabled && (envelopeOutcome(rule, data, 'enabled') ?? true);
  }

  return { visible, enabled };
}

/**
 * Final verdicts for a normalized form against the current form values —
 * the client-side twin of Eligibility::fieldVerdicts(). Group composition
 * is AND. serverResolved fields/groups use the server-shipped verdict
 * (refreshed only by re-fetching).
 *
 * Values are completed to the full declared field map (defaults, else
 * null) so both runtimes evaluate against the same shape.
 */
export function fieldVerdicts(
  form: { fields: FormField[]; groups: GroupDescriptor[]; resolved: Record<string, Verdict>; resolvedGroups: Record<string, Verdict> },
  values: Data,
): Record<string, Verdict> {
  const data = completeData(form.fields, values);

  const groupVerdict = new Map<string, Verdict>();
  for (const group of form.groups) {
    if (group.serverResolved) {
      const shipped = form.resolvedGroups[group.name];
      if (shipped) groupVerdict.set(group.name, shipped);
      continue;
    }
    if (group.eligibility && group.eligibility.length > 0) {
      groupVerdict.set(group.name, resolveRules(group.eligibility, data));
    }
  }

  const verdicts: Record<string, Verdict> = {};
  for (const field of form.fields) {
    const own = field.serverResolved
      ? (form.resolved[field.name] ?? { visible: false, enabled: false })
      : field.eligibility && field.eligibility.length > 0
        ? resolveRules(field.eligibility, data)
        : null;

    const group = field.group ? groupVerdict.get(field.group) : undefined;

    if (own === null && group === undefined) continue;

    const base = own ?? { visible: true, enabled: true };
    verdicts[field.name] = group
      ? { visible: base.visible && group.visible, enabled: base.enabled && group.enabled }
      : base;
  }

  return verdicts;
}

/** Every declared field present: given value, else descriptor default, else null. */
export function completeData(fields: FormField[], values: Data): Data {
  const data: Data = {};
  for (const field of fields) {
    data[field.name] = Object.prototype.hasOwnProperty.call(values, field.name)
      ? (values[field.name] === undefined ? null : values[field.name])
      : (field.default ?? null);
  }
  return data;
}
