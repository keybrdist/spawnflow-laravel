import type { SpawnClient } from './contract';
export interface HttpClientOptions {
    baseUrl?: string;
    headers?: Record<string, string> | (() => Record<string, string>);
    fetch?: typeof fetch;
}
/**
 * SpawnClient over real Spawnflow routes: SpawnflowController CRUD +
 * schema/options endpoints. 422s map to field errors.
 */
export declare function createHttpClient(options?: HttpClientOptions): SpawnClient;
export type SubjectChange = {
    subject: string;
    version: number;
};
/**
 * Subscribe to the opt-in SSE invalidation channel
 * (GET /spawnflow/events). Signals only — refetch through the client on
 * change. EventSource auto-reconnects; a dropped stream degrades to
 * non-live, never to wrong data. Returns an unsubscribe function.
 */
export declare function subscribeToChanges(onChange: (change: SubjectChange) => void, options?: {
    baseUrl?: string;
    subjects?: string[];
}): () => void;
