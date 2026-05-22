<?php

declare(strict_types=1);

return [

    /*
     * Master switch. Set to false to turn the package into a complete no-op without
     * removing the HasIdentityMap trait from your models — useful during incidents
     * or when rolling out to production incrementally.
     */
    'enabled' => (bool) env('IDENTITY_MAP_ENABLED', true),

    /*
     * Controls how aggressively the identity map serves queries from memory.
     *
     *   identity            — exact primary-key identity reuse only. A second find()
     *                         for an already-loaded id returns the cached instance with
     *                         zero SQL.
     *   primary_key_rewrite — everything in "identity", plus whereKey() chains are
     *                         rewritten to return cached instances without executing SQL.
     *                         This is the recommended default.
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
     * Which attribute values are considered authoritative when returning a cached model.
     *
     *   database_only  — only attributes confirmed by a DB read or a successful save()
     *                    are used for predicate evaluation.
     *   process_truth  — current in-memory values (including dirty changes) are the
     *                    authoritative snapshot for query evaluation.
     */
    'attribute_truth' => env('IDENTITY_MAP_ATTRIBUTE_TRUTH', 'database_only'),

    /*
     * What to do when a cached model lacks columns required by a query.
     *
     *   query_normally           — fall through to SQL to fetch the full row.
     *   backfill_missing_columns — issue a targeted SQL query for missing columns only
     *                              and merge the result into the existing entry.
     */
    'partial_models' => env('IDENTITY_MAP_PARTIAL_MODELS', 'query_normally'),

    /*
     * Track which keys are known to be absent so repeated lookups for non-existent
     * rows skip SQL entirely. Each key type can be toggled independently.
     *
     *   primary_key — track absent finds by primary key (e.g. User::find(99999)).
     *   unique_key  — track absent unique-key lookups (requires 'unique' config in 'models').
     *   coverage    — track absent results for coverage-mode scopes.
     */
    'absence_tracking' => [
        'primary_key' => (bool) env('IDENTITY_MAP_ABSENCE_TRACKING_PK', true),
        'unique_key' => (bool) env('IDENTITY_MAP_ABSENCE_TRACKING_UK', true),
        'coverage' => (bool) env('IDENTITY_MAP_ABSENCE_TRACKING_COVERAGE', true),
    ],

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
     * SQL constructs that defeat safe query-elision. When a query contains one of these,
     * the map declines to answer it from memory and falls through to SQL regardless of
     * what is cached. Set a key to true only if you accept the risk of serving a stale answer.
     */
    'hazards' => [
        'allow_raw_wheres' => (bool) env('IDENTITY_MAP_ALLOW_RAW_WHERES', false),
        'allow_joins' => (bool) env('IDENTITY_MAP_ALLOW_JOINS', false),
        'allow_unions' => (bool) env('IDENTITY_MAP_ALLOW_UNIONS', false),
        'allow_locks' => (bool) env('IDENTITY_MAP_ALLOW_LOCKS', false),
        'allow_aggregates' => (bool) env('IDENTITY_MAP_ALLOW_AGGREGATES', false),
        'allow_subqueries' => (bool) env('IDENTITY_MAP_ALLOW_SUBQUERIES', false),
    ],

    /*
     * Write-through policies — what happens to cached entries when the database is mutated.
     *
     * Model saves (save() / update() on a loaded instance):
     *   invalidate_coverage — the model entry is refreshed in the map; any coverage entries
     *                         that might include this model are evicted.
     *   flush_model         — all entries for this model class are dropped.
     *
     * Mass operations (UPDATE/DELETE without loading model instances):
     *   flush_model  — drop all entries for the affected model class.
     *   flush_all    — drop the entire identity map.
     *
     * Raw writes (DB::statement, raw queries):
     *   flush_model  — drop entries for whichever class triggered the event (best effort).
     *   flush_all    — drop the entire identity map (safe default).
     */
    'writes' => [
        'model_save_policy' => env('IDENTITY_MAP_SAVE_POLICY', 'invalidate_coverage'),
        'mass_update_policy' => env('IDENTITY_MAP_MASS_UPDATE_POLICY', 'flush_model'),
        'mass_delete_policy' => env('IDENTITY_MAP_MASS_DELETE_POLICY', 'flush_model'),
        'raw_write_policy' => env('IDENTITY_MAP_RAW_WRITE_POLICY', 'flush_all'),
    ],

    /*
     * Observability and debugging. Only enable in non-production environments.
     *
     *   enabled           — log identity-map decisions to the configured channel.
     *   log_channel       — Laravel log channel name (null = default channel).
     *   include_backtraces — attach a stack trace to each log entry (expensive).
     */
    'debug' => [
        'enabled' => (bool) env('IDENTITY_MAP_DEBUG', false),
        'log_channel' => env('IDENTITY_MAP_LOG_CHANNEL'),
        'include_backtraces' => (bool) env('IDENTITY_MAP_INCLUDE_BACKTRACES', false),
    ],

];
