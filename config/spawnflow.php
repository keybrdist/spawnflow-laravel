<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subject Registry
    |--------------------------------------------------------------------------
    |
    | Maps URL segment strings to Eloquent model classes. Adding a resource
    | to Spawnflow starts here.
    |
    */
    'subjects' => [
        // 'campaigns'   => \App\Models\Campaign::class,
        // 'subscribers' => \App\Models\Subscriber::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Enums (Field-Level Permissions)
    |--------------------------------------------------------------------------
    |
    | Maps each subject to its FieldContext enum class. Subjects without a
    | context use default behavior: all $fillable fields are writable by owner.
    |
    */
    'contexts' => [
        // 'campaigns' => \App\Spawnflow\CampaignContext::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Field Descriptors
    |--------------------------------------------------------------------------
    |
    | Maps each subject to its FieldSet class — the type-aware field
    | descriptors (type, widget, label, rules, enum options, relations)
    | that the schema endpoint and generator serialize from. Subjects
    | without a FieldSet fall back to minimal inferred descriptors.
    |
    */
    'fields' => [
        // 'campaigns' => \App\Spawnflow\CampaignFields::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attribute Discovery
    |--------------------------------------------------------------------------
    |
    | FieldSets carrying #[SpawnSubject('alias', model: ..., context: ...)]
    | under the discovery path register themselves — no config entry
    | needed (spawnflow:resource generates them this way). Config entries
    | above override discovered ones on conflict. Deploy-time:
    | `spawnflow:cache` freezes the scan; `spawnflow:clear` unfreezes.
    |
    */
    'discovery' => true,
    'discovery_path' => null, // defaults to app_path('Spawnflow')

    /*
    |--------------------------------------------------------------------------
    | SSE Invalidation Channel (opt-in)
    |--------------------------------------------------------------------------
    |
    | GET /spawnflow/events streams `change` signals when subjects are
    | written through the Flow chain — invalidation only, never state:
    | clients refetch via the endpoints they already use, so a dropped
    | stream degrades to non-live, never to wrong data. Registered with
    | the schema routes, behind the same middleware. Each open stream
    | holds a PHP worker; set events_max_polls to recycle idle streams
    | (EventSource reconnects automatically).
    |
    */
    'events' => false,
    'events_poll_interval' => 2, // seconds between version checks
    'events_max_polls' => null, // null = stream until the client disconnects
    'events_cache_store' => null, // null = default cache store

    /*
    |--------------------------------------------------------------------------
    | Ownership
    |--------------------------------------------------------------------------
    |
    | The database column that links records to their owner, and the
    | corresponding key on the User model.
    |
    */
    'ownership_column' => 'ownerId',
    'user_key' => 'id',

    /*
    |--------------------------------------------------------------------------
    | Schema Routes
    |--------------------------------------------------------------------------
    |
    | When enabled, registers GET /spawnflow/schema/{subject}/{id?} routes
    | for serving field permission schemas to the frontend.
    |
    */
    'schema_routes' => false,
    'schema_middleware' => ['auth:api'],

    /*
    |--------------------------------------------------------------------------
    | Generator
    |--------------------------------------------------------------------------
    |
    | Configuration for the frontend type generation commands.
    |
    */
    'generator' => [
        'output_path' => base_path('../frontend/src/generated'),
        'type_format' => 'typescript',
        'validation' => 'zod',
        'emit_client' => true,
        'emit_unions' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Server
    |--------------------------------------------------------------------------
    |
    | The contract, queryable and operable by AI agents. Disabled by
    | default — registration is a no-op when off. `enabled` exposes the
    | stdio (local/dev) server; `web` additionally exposes the streamable
    | HTTP transport behind `web_middleware` (the authenticated token's
    | user IS the Flow user — ownership, contexts, eligibility and wire
    | coercion apply exactly as on the HTTP path). Dev tools (scaffold,
    | generate) register only in the local environment over stdio,
    | regardless of these flags.
    |
    */
    'mcp' => [
        'enabled' => false,
        'web' => false,
        'web_route' => '/mcp/spawnflow',
        'web_middleware' => ['auth:api', 'throttle:60,1'],
    ],
];
