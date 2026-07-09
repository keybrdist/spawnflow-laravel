import { z } from 'zod';
import type { FormField, StructuredRule } from './contract';
/**
 * Runtime compiler: structured contract rules → Zod. The client-side twin
 * of the package's ZodCompiler — both implement the mapping specified in
 * docs/schema-contract.md. serverOnly rules are never compiled; fields
 * carrying them still need a server pass (Precognition or submit).
 */
export declare function compileField(field: FormField): z.ZodTypeAny;
/**
 * Object schema for a field list: editable fields compile; fields with a
 * `confirmed` rule gain a paired `{name}_confirmation` field and a
 * match refinement.
 */
export declare function compileForm(fields: FormField[]): z.ZodTypeAny;
/** Field names carrying rules only the server can check. */
export declare function serverOnlyFields(fields: FormField[]): string[];
export type { StructuredRule };
