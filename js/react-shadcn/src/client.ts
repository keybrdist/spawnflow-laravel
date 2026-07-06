import type { OptionsPage, Schema, SpawnClient, SubmitResult } from './contract';

export interface HttpClientOptions {
  baseUrl?: string;
  headers?: Record<string, string> | (() => Record<string, string>);
  fetch?: typeof fetch;
}

/**
 * SpawnClient over real Spawnflow routes: SpawnflowController CRUD +
 * schema/options endpoints. 422s map to field errors.
 */
export function createHttpClient(options: HttpClientOptions = {}): SpawnClient {
  const baseUrl = (options.baseUrl ?? '').replace(/\/$/, '');
  const doFetch = options.fetch ?? fetch.bind(globalThis);

  async function request(method: string, path: string, body?: unknown): Promise<Response> {
    const headers = typeof options.headers === 'function' ? options.headers() : (options.headers ?? {});
    return doFetch(`${baseUrl}${path}`, {
      method,
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...headers },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  }

  return {
    async schema(subject, id) {
      const response = await request('GET', `/spawnflow/schema/${subject}${id === undefined ? '' : `/${id}`}`);
      if (!response.ok) throw new Error(`Schema fetch failed: ${response.status}`);
      return (await response.json()) as Schema;
    },

    async options(subject, field, params = {}) {
      const query = new URLSearchParams();
      if (params.q) query.set('q', params.q);
      if (params.page) query.set('page', String(params.page));
      const qs = query.size ? `?${query}` : '';
      const response = await request('GET', `/spawnflow/options/${subject}/${field}${qs}`);
      if (!response.ok) throw new Error(`Options fetch failed: ${response.status}`);
      return (await response.json()) as OptionsPage;
    },

    async submit(subject, values, id): Promise<SubmitResult> {
      const response = await request('POST', id === undefined ? `/${subject}` : `/${subject}/${id}`, values);
      if (response.ok) {
        return { ok: true, data: response.status === 204 ? undefined : await response.json() };
      }
      if (response.status === 422) {
        const body = (await response.json()) as { errors?: Record<string, string[]>; message?: string };
        return { ok: false, errors: body.errors ?? {}, message: body.message };
      }
      return { ok: false, errors: {}, message: `Request failed: ${response.status}` };
    },
  };
}
