/**
 * SpawnClient over real Spawnflow routes: SpawnflowController CRUD +
 * schema/options endpoints. 422s map to field errors.
 */
export function createHttpClient(options = {}) {
    const baseUrl = (options.baseUrl ?? '').replace(/\/$/, '');
    const doFetch = options.fetch ?? fetch.bind(globalThis);
    async function request(method, path, body) {
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
            if (!response.ok)
                throw new Error(`Schema fetch failed: ${response.status}`);
            return (await response.json());
        },
        async options(subject, field, params = {}) {
            const query = new URLSearchParams();
            if (params.q)
                query.set('q', params.q);
            if (params.page)
                query.set('page', String(params.page));
            const qs = query.size ? `?${query}` : '';
            const response = await request('GET', `/spawnflow/options/${subject}/${field}${qs}`);
            if (!response.ok)
                throw new Error(`Options fetch failed: ${response.status}`);
            return (await response.json());
        },
        async submit(subject, values, id) {
            const response = await request('POST', id === undefined ? `/${subject}` : `/${subject}/${id}`, values);
            if (response.ok) {
                return { ok: true, data: response.status === 204 ? undefined : await response.json() };
            }
            if (response.status === 422) {
                const body = (await response.json());
                return { ok: false, errors: body.errors ?? {}, message: body.message };
            }
            return { ok: false, errors: {}, message: `Request failed: ${response.status}` };
        },
    };
}
/**
 * Subscribe to the opt-in SSE invalidation channel
 * (GET /spawnflow/events). Signals only — refetch through the client on
 * change. EventSource auto-reconnects; a dropped stream degrades to
 * non-live, never to wrong data. Returns an unsubscribe function.
 */
export function subscribeToChanges(onChange, options = {}) {
    const baseUrl = (options.baseUrl ?? '').replace(/\/$/, '');
    const query = options.subjects?.length ? `?subjects=${options.subjects.join(',')}` : '';
    const source = new EventSource(`${baseUrl}/spawnflow/events${query}`);
    source.addEventListener('change', (event) => {
        onChange(JSON.parse(event.data));
    });
    return () => source.close();
}
