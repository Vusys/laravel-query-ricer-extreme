<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('IDENTITY_MAP_ENABLED', true),

    /*
     * identity               — only exact primary-key identity reuse (current default)
     * primary_key_rewrite    — bounded primary-key query pruning
     * predicate              — evaluate simple predicates against known models
     * unique_key             — positive unique-key hits
     * coverage               — answer whole query regions from memory
     * process_truth          — unsaved in-memory changes affect query results
     */
    'mode' => env('IDENTITY_MAP_MODE', 'identity'),

    /*
     * database_only  — only attributes hydrated from DB or saved successfully
     * process_truth  — current in-memory values are authoritative
     */
    'attribute_truth' => 'database_only',

    /*
     * query_normally          — execute SQL when columns are missing
     * backfill_missing_columns — query only missing columns and merge
     */
    'partial_models' => 'query_normally',

    'absence_tracking' => [
        'primary_key' => true,
        'unique_key' => false,
        'coverage' => false,
    ],

    'models' => [
        /*
         * App\Models\User::class => [
         *     'unique' => [
         *         ['email'],
         *         ['tenant_id', 'slug'],
         *     ],
         * ],
         */
    ],

    'limits' => [
        'max_models_per_class' => 1000,
        'max_total_models' => 10000,
        'max_coverage_entries' => 1000,
        'max_rewrite_keys' => 5000,
    ],

    'hazards' => [
        'allow_raw_wheres' => false,
        'allow_joins' => false,
        'allow_unions' => false,
        'allow_locks' => false,
        'allow_aggregates' => false,
        'allow_subqueries' => false,
    ],

    'writes' => [
        'model_save_policy' => 'invalidate_coverage',
        'mass_update_policy' => 'flush_model',
        'mass_delete_policy' => 'flush_model',
        'raw_write_policy' => 'flush_all',
    ],

    'debug' => [
        'enabled' => (bool) env('IDENTITY_MAP_DEBUG', false),
        'log_channel' => env('IDENTITY_MAP_LOG_CHANNEL'),
        'include_backtraces' => false,
    ],
];
