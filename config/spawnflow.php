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
