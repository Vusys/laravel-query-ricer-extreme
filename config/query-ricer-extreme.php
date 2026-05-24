<?php

declare(strict_types=1);

return [

    /*
     * Controls how aggressively the identity map serves queries from memory.
     *
     *   identity            — exact primary-key identity reuse only. A second find()
     *                         for an already-loaded id returns the cached instance with
     *                         zero SQL.
     *   primary_key_rewrite — everything in "identity", plus whereKey() chains are
     *                         rewritten to return cached instances without executing SQL.
     *   predicate           — evaluate simple WHERE predicates against known attributes
     *                         before falling through to SQL.
     *   unique_key          — serve positive unique-key lookups (e.g. find-by-email) from
     *                         memory when the column is declared in 'models' below.
     *   coverage            — answer entire query regions from memory when all matching
     *                         models for a given scope are already loaded.
     *   process_truth       — treat unsaved in-memory attribute changes as authoritative
     *                         when evaluating predicates. Implies predicate mode.
     */
    'mode' => env('IDENTITY_MAP_MODE', 'primary_key_rewrite'),

    /*
     * Per-model configuration. Declare unique column sets here to enable unique-key
     * mode lookups and absence tracking by columns other than the primary key.
     *
     * Example:
     *
     *   App\Models\User::class => [
     *       'unique' => [
     *           ['email'],
     *           ['tenant_id', 'slug'],
     *       ],
     *   ],
     */
    'models' => [
    ],

    /*
     * Automatic discovery of unique indexes from the database schema. When enabled,
     * the identity map inspects each model's table on first use and registers any
     * unique indexes it finds — including compound indexes — so unique-key elision
     * fires without requiring entries in 'models' above. Config-declared indexes
     * take precedence; discovery supplements them, does not replace them.
     *
     *   enabled            — set false to disable schema introspection entirely.
     *   cache_per_request  — reserved; discovery is currently always cached for the
     *                        lifetime of the request/job scope.
     */
    'schema_discovery' => [
        'enabled' => (bool) env('IDENTITY_MAP_SCHEMA_DISCOVERY', true),
        'cache_per_request' => true,
    ],

    /*
     * Hard memory caps. Entries beyond these limits are not cached; the query falls
     * through to SQL. Tune downward in memory-constrained environments.
     */
    'limits' => [
        'max_models_per_class' => (int) env('IDENTITY_MAP_MAX_MODELS_PER_CLASS', 1000),
        'max_total_models' => (int) env('IDENTITY_MAP_MAX_TOTAL_MODELS', 10000),
        'max_coverage_entries' => (int) env('IDENTITY_MAP_MAX_COVERAGE_ENTRIES', 1000),
        'max_rewrite_keys' => (int) env('IDENTITY_MAP_MAX_REWRITE_KEYS', 5000),
    ],

    /*
     * Observability and debugging. Only enable in non-production environments.
     *
     *   enabled            — log identity-map decisions to the configured channel.
     *   log_channel        — Laravel log channel name (null = default channel).
     *   include_backtraces — attach a stack trace to each log entry (expensive).
     */
    'debug' => [
        'enabled' => (bool) env('IDENTITY_MAP_DEBUG', false),
        'log_channel' => env('IDENTITY_MAP_LOG_CHANNEL'),
        'include_backtraces' => (bool) env('IDENTITY_MAP_INCLUDE_BACKTRACES', false),
    ],

];
