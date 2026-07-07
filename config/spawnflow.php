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
];
