import type { Condition, EligibilityRule, FormField, GroupDescriptor, Verdict } from './contract';
export declare class InvalidConditionError extends Error {
}
type Data = Record<string, unknown>;
/**
 * Explicit truthiness, identical in both runtimes: null/false falsy,
 * numbers falsy at zero, strings falsy when empty ("0" IS truthy),
 * arrays/objects falsy when empty.
 */
export declare function truthy(value: unknown): boolean;
/** Whether the condition passes against the given data. Throws InvalidConditionError. */
export declare function passes(condition: Condition, data: Data): boolean;
/** Field names referenced by a condition's var/missing nodes (root segments, deduplicated). */
export declare function references(condition: Condition): string[];
/** Per-axis verdict for a set of envelopes: AND per axis, default eligible. */
export declare function resolveRules(rules: EligibilityRule[], data: Data): Verdict;
/**
 * Final verdicts for a normalized form against the current form values —
 * the client-side twin of Eligibility::fieldVerdicts(). Group composition
 * is AND. serverResolved fields/groups use the server-shipped verdict
 * (refreshed only by re-fetching).
 *
 * Values are completed to the full declared field map (defaults, else
 * null) so both runtimes evaluate against the same shape.
 */
export declare function fieldVerdicts(form: {
    fields: FormField[];
    groups: GroupDescriptor[];
    resolved: Record<string, Verdict>;
    resolvedGroups: Record<string, Verdict>;
}, values: Data): Record<string, Verdict>;
/** Every declared field present: given value, else descriptor default, else null. */
export declare function completeData(fields: FormField[], values: Data): Data;
export {};
