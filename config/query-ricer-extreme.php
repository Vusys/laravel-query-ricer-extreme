<?php

declare(strict_types=1);

return [

    /*
     * Controls how the identity map evaluates predicates against cached models.
     *
     *   default       — predicates evaluate against the last-committed attribute
     *                   value. Dirty in-memory mutations are ignored until save().
     *                   Safe default — never returns a row the database would not.
     *   process_truth — predicates evaluate against the current in-memory value,
     *                   which may be dirty. Lets pending mutations affect query
     *                   results within the same request. The unique-key index is
     *                   bypassed under this mode because it is keyed on original
     *                   values; querying it with dirty values would mislead.
     *
     * The set of optimizations the package performs (primary-key reuse, key-set
     * rewriting, unique-key lookup, coverage, relation graph, whereHas rewrite)
     * is not configurable — they are always on. Disable per query with
     * `->withoutIdentityMap()` or per scope with `IdentityMap::disabled(...)`.
     */
    'mode' => env('IDENTITY_MAP_MODE', 'default'),

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
     */
    'schema_discovery' => [
        'enabled' => (bool) env('IDENTITY_MAP_SCHEMA_DISCOVERY', true),
    ],

    /*
     * Partial model column backfill.
     *
     *   query_normally           — when a cached model is missing a requested
     *                              column, execute the full original query. The
     *                              map is updated with the wider row. Safe
     *                              default — no behavioural change.
     *   backfill_missing_columns — issue a narrow SELECT for the missing columns
     *                              only, keyed on the cached model's primary key,
     *                              and merge the fetched columns into the
     *                              existing instance. Dirty in-memory attributes
     *                              are preserved (the merge only updates
     *                              AttributeFact::originalValue for dirty
     *                              columns; currentValue and the model's actual
     *                              attribute stay as the dirty value).
     *
     * Only point lookups (find / unique-key / MemoryBelongsTo) backfill. Coverage
     * and whereHas paths still fall through to a full SELECT when columns are
     * missing.
     */
    'partial_models' => env('IDENTITY_MAP_PARTIAL_MODELS', 'query_normally'),

    /*
     * Identity graph: tracks model-to-model relation edges so that relation
     * queries can be answered from memory when coverage is complete.
     *
     *   enabled                          — turn the graph on or off entirely.
     *   max_edges / max_coverage_entries — hard caps; when exceeded the graph
     *                                      is flushed entirely (safest).
     */
    'relation_graph' => [
        'enabled' => (bool) env('IDENTITY_MAP_RELATION_GRAPH_ENABLED', true),
        'max_edges' => (int) env('IDENTITY_MAP_RELATION_GRAPH_MAX_EDGES', 50000),
        'max_coverage_entries' => (int) env('IDENTITY_MAP_RELATION_GRAPH_MAX_COVERAGE', 5000),
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

];
