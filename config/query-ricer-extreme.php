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
     * Identity graph: tracks model-to-model relation edges so that relation
     * queries can be answered from memory when coverage is complete.
     *
     *   enabled                        — turn the graph on or off entirely.
     *   sync_loaded_inverse_relations  — when a belongsTo association mutates,
     *                                    keep the old/new parent's loaded
     *                                    relation collections in sync.
     *   max_edges / max_coverage_entries — hard caps; when exceeded the graph
     *                                    is flushed entirely (safest).
     */
    'relation_graph' => [
        'enabled' => (bool) env('IDENTITY_MAP_RELATION_GRAPH_ENABLED', true),
        'sync_loaded_inverse_relations' => (bool) env('IDENTITY_MAP_RELATION_GRAPH_SYNC_INVERSE', true),
        'max_edges' => (int) env('IDENTITY_MAP_RELATION_GRAPH_MAX_EDGES', 50000),
        'max_coverage_entries' => (int) env('IDENTITY_MAP_RELATION_GRAPH_MAX_COVERAGE', 5000),
        'eviction' => 'flush_scope',
    ],

    /*
     * Per-driver comparison semantics. Controls how the predicate evaluator
     * resolves string equality.
     *
     *   database_collation    — read the column collation reported by
     *                           Schema::getColumns(); compare under that
     *                           collation. Falls back to the driver default
     *                           (case-sensitive for SQLite/Postgres, Unknown
     *                           for MySQL/MariaDB) when collation is missing.
     *                           Default — uses authoritative metadata.
     *   php_strict            — treat every string column as case-sensitive
     *                           byte-equality. Fast, but wrong on MySQL with
     *                           case-insensitive collations.
     *   conservative_unknown  — return Unknown for string comparisons and let
     *                           SQL handle it. Maximally safe but eliminates
     *                           most string-predicate elision.
     *
     * Integer, boolean, UUID, and null comparisons are always resolved
     * confidently — this setting only affects string semantics.
     */
    'database_semantics' => [
        'sqlite' => [
            'string_comparisons' => env('IDENTITY_MAP_SQLITE_STRING_COMPARISONS', 'database_collation'),
        ],
        'mysql' => [
            'string_comparisons' => env('IDENTITY_MAP_MYSQL_STRING_COMPARISONS', 'database_collation'),
        ],
        'mariadb' => [
            'string_comparisons' => env('IDENTITY_MAP_MARIADB_STRING_COMPARISONS', 'database_collation'),
        ],
        'pgsql' => [
            'string_comparisons' => env('IDENTITY_MAP_PGSQL_STRING_COMPARISONS', 'database_collation'),
        ],
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
