# Laravel Query Ricer: Extreme

[![Tests](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml) [![codecov](https://codecov.io/gh/Vusys/laravel-query-ricer-extreme/graph/badge.svg)](https://codecov.io/gh/Vusys/laravel-query-ricer-extreme) [![tests](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/tests.json)](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml) [![assertions](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/assertions.json)](https://github.com/Vusys/laravel-query-ricer-extreme/actions/workflows/tests.yml) [![test LOC](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/test-ratio.json)](tests/) [![CI matrix](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-query-ricer-extreme/badges/matrix.json)](.github/workflows/tests.yml) [![Bencher](https://img.shields.io/badge/Bencher-tracked-FD6F1B?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0id2hpdGUiPjxwYXRoIGQ9Ik0xMiAyTDMgN3YxMGw5IDUgOS01VjdaIi8+PC9zdmc+)](https://bencher.dev/perf/vusys-laravel-query-ricer-extreme) [![Mutation testing](https://img.shields.io/endpoint?style=flat&url=https://badge-api.stryker-mutator.io/github.com/Vusys/laravel-query-ricer-extreme/master)](https://dashboard.stryker-mutator.io/reports/github.com/Vusys/laravel-query-ricer-extreme/master) [![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Vusys/laravel-query-ricer-extreme/badge)](https://scorecard.dev/viewer/?uri=github.com/Vusys/laravel-query-ricer-extreme) [![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](composer.json) [![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel)](composer.json) [![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon) [![Rector](https://img.shields.io/badge/Rector-passing-brightgreen.svg)](rector.php) [![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-FF2D20.svg?logo=laravel)](https://github.com/laravel/pint) [![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

> Rice your Eloquent queries until the database wonders where everybody went.

A scoped Eloquent identity map, process-truth engine, and query-elision planner for Laravel.

---

- [About](#about)
  - [The problem it solves](#the-problem-it-solves)
  - [What it is — and what it is not](#what-it-is--and-what-it-is-not)
  - [What it does](#what-it-does)
  - [When it helps and when it does not apply](#when-it-helps-and-when-it-does-not-apply)
- [Installation](#installation)
- [Usage](#usage)
  - [Opt in per model](#opt-in-per-model)
  - [Per-query opt-out](#per-query-opt-out)
  - [Manual flush](#manual-flush)
  - [Disable for a scope](#disable-for-a-scope)
  - [Unique-key lookups](#unique-key-lookups)
  - [Explain a query decision](#explain-a-query-decision)
  - [Supported in-memory predicates](#supported-in-memory-predicates)
  - [Relation optimizations](#relation-optimizations)
- [Architecture and internals](#architecture-and-internals)
  - [High-level data flow](#high-level-data-flow)
  - [Opt-in mechanism: HasIdentityMap](#opt-in-mechanism-hasidentitymap)
  - [The store: IdentityMapStore and IdentityEntry](#the-store-identitymapstore-and-identityentry)
  - [Scope isolation: ScopeFingerprinter](#scope-isolation-scopefingerprinter)
  - [Query classification: QueryPatternExtractor](#query-classification-querypatternextractor)
  - [Predicate evaluation](#predicate-evaluation)
  - [Attribute knowledge](#attribute-knowledge)
  - [Unique-key index](#unique-key-index)
  - [Coverage: CoverageRegistry and SubsetChecker](#coverage-coverageregistry-and-subsetchecker)
  - [Process-truth vs database-truth](#process-truth-vs-database-truth)
  - [Identity graph](#identity-graph-relation_graph)
  - [Partial models & column backfill](#partial-models--column-backfill-partial_models)
  - [Schema discovery](#schema-discovery-schema_discovery)
  - [Driver semantics](#driver-semantics-database_semantics)
  - [Lifecycle hooks and automatic flushing](#lifecycle-hooks-and-automatic-flushing)
  - [Mass writes](#mass-writes)
  - [Observability: explain()](#observability-explain)
- [Test suite](#test-suite)
  - [Overview](#overview)
  - [Unit tests](#unit-tests)
  - [Feature tests](#feature-tests)
  - [Cartesian / data-provider tests](#cartesian--data-provider-tests)
  - [Fuzz tests](#fuzz-tests)
  - [Performance tests](#performance-tests)
  - [Mutation testing](#mutation-testing)
  - [How the suites complement each other](#how-the-suites-complement-each-other)
- [Publish config](#publish-config)
- [Requirements](#requirements)
- [License](#license)

---

## About

### The problem it solves

In a typical Laravel request, the same model is often fetched more than once. A middleware loads `$user`, the controller loads it again, an authorization policy loads it a third time, and a view composer fetches it once more. Each call issues its own `SELECT`. Eager loading and manual caching can prevent this, but both require deliberate coordination at every call site — and that discipline erodes as a codebase grows.

This package hooks into the Eloquent query pipeline so that elision is automatic. No changes are needed to controllers, policies, or view composers. Any model that opts in simply stops issuing redundant SQL for the duration of the current request or job.

### What it is — and what it is not

It is an **in-process identity map** scoped to a single request, queue job, or Octane worker turn. Within that scope, a hydrated model instance is treated as the authoritative fact about its own known attributes. Subsequent queries that can be answered from memory are answered from memory; all others fall through to SQL unchanged.

It is not a distributed cache. It is not a query-result cache. Nothing is serialized, stored to Redis, or shared between processes. When the scope ends (the HTTP response is sent, the job finishes), all in-memory entries are discarded.

It operates at the Eloquent Builder level — overriding `find`, `getModels` (which powers `get`), `first`, `sole`, `exists`, `count`, `pluck`, `sum`, `min`, `max`, `avg`/`average`, `update`, `delete`, `forceDelete`, `whereHas`, and `whereDoesntHave`. `value()` and `firstOrFail()` are served indirectly through the overridden `first()`. Relation resolution is also intercepted (see [Relation optimizations](#relation-optimizations)). Raw `DB::` queries and queries on models that do not use the `HasIdentityMap` trait are never touched.

The package goes further than the classic single-key identity map. It rewrites key-set queries so only genuinely unknown IDs hit the database, evaluates `WHERE` predicates against cached attributes to prune query results in memory, serves `where('email', ...)->first()` style lookups from a secondary unique-key index, and tracks entire query regions so broad `->get()` calls can prime the map for narrower follow-up queries.

### What it does

**Exact primary key** — zero SQL if already in memory:

```php
$userA = User::find(1);
$userB = User::find(1);

$userA === $userB; // true — no second query
```

**Key-set queries** — only unknown keys hit the database:

```php
User::find([1, 2, 3, 4]);
// Already in map: 1, 2. Confirmed absent: 3.
// SQL: SELECT * FROM users WHERE id IN (4)
// Result merged in original-key order: [user#1, user#2, null, user#4]
```

**Predicate evaluation** — extra `where` conditions evaluated in memory before SQL:

```php
// Map already holds user#1 (active=1) and user#2 (active=0).
User::whereKey([1, 2, 3])->where('active', true)->get();
// user#1: active == true → Match, returned from memory
// user#2: active == false → Reject, excluded without SQL
// user#3: not in map → queried
// SQL: SELECT * FROM users WHERE id IN (3) AND active = 1
```

**Unique-key queries** — `first()`, `firstOrFail()`, `sole()`, `value()`, and `exists()` can be served from memory when the column is declared unique in config:

```php
User::find(1); // email = alice@example.com now in map

User::where('email', 'alice@example.com')->first();          // no SQL
User::where('email', 'alice@example.com')->value('email');   // no SQL
User::where('email', 'alice@example.com')->exists();         // no SQL — true
```

**Relation resolution** — `belongsTo`, `morphTo`, `hasMany`, and `morphMany` are also served from memory when possible:

```php
$post = Post::find(1);   // User already in the map from earlier work

$post->user;             // no SQL — cached User returned directly

$user->load('posts');
$user->posts()->where('published', true)->get();   // no SQL — filtered in memory
```

**Coverage mode** — loading a broad result set primes the map for narrower follow-up queries:

```php
User::where('active', true)->get();  // loads all active users; coverage recorded

// Later in the same request:
User::where('active', true)->where('role', 'admin')->get();  // no SQL — subset answered from memory
```

**process_truth** (`mode = 'process_truth'` in config) — unsaved in-memory attribute changes are treated as authoritative:

```php
$user->active = false;  // dirty, not yet saved

// Under process_truth, predicates evaluate against the current in-memory value:
User::whereKey([$user->id])->where('active', true)->get();  // → empty, no SQL
```

Absent-key tracking means the package remembers which primary keys and unique-key values returned nothing from a previous query. If those same lookups are repeated under the same scope, no SQL is issued.

### When it helps and when it does not apply

**Helps most when:**

- A controller, service, policy, and event listener each load the same user model independently.
- A job processes a batch of records that all reference the same parent model (e.g. thousands of order lines sharing a handful of product records).
- An API endpoint assembles a resource from several related models that were already loaded earlier in the same request.
- A legacy codebase cannot be refactored to add eager loading at every call site.

**Does not apply when:**

- The query uses aggregates (`SUM`, `AVG`, `GROUP BY`), raw SQL, joins, or unions.
- The model does not use the `HasIdentityMap` trait.
- You need results to persist across requests (use a cache layer instead).
- The query involves pessimistic locking (`lockForUpdate`, `sharedLock`).

---

## Installation

```bash
composer require vusys/laravel-query-ricer-extreme
```

## Usage

### Opt in per model

```php
use Vusys\QueryRicerExtreme\HasIdentityMap;

final class User extends Model
{
    use HasIdentityMap;
}
```

### Per-query opt-out

```php
User::query()->withoutIdentityMap()->find(1);
```

### Manual flush

```php
use Vusys\QueryRicerExtreme\IdentityMap;

IdentityMap::flush();               // all entries
IdentityMap::flush(User::class);    // one model class
IdentityMap::forget($user);         // one instance
```

### Disable for a scope

```php
IdentityMap::disabled(function () {
    return User::find(1); // always hits DB
});
```

### Unique-key lookups

Declare unique indexes in the published config to allow `where('col', value)->first()` style queries to be answered from memory:

```php
// config/query-ricer-extreme.php
'models' => [
    App\Models\User::class => [
        'unique' => [
            ['email'],
            ['tenant_id', 'slug'],  // compound key
        ],
    ],
],
```

Once configured, any of the following can be served without SQL when the model is already in the identity map:

```php
User::where('email', 'alice@example.com')->first();
User::where('email', 'alice@example.com')->firstOrFail();
User::where('email', 'alice@example.com')->sole();
User::where('email', 'alice@example.com')->value('email');
User::where('email', 'alice@example.com')->exists();
```

Additional `where` conditions on top of the unique key are evaluated in memory using the same predicate engine used for key-set queries. If the extra predicate can be evaluated and the unique key match is rejected, the package returns `null` or `false` with no SQL — because the unique key guarantees there can be no other row with that value.

```php
// Map holds user#1: email = alice@example.com, active = true
User::where('email', 'alice@example.com')->where('active', false)->first();
// → null, no SQL. The unique key found the only candidate; active = false rejects it.
```

Absence is also tracked: if a unique-key lookup returns `null` from the database, subsequent identical lookups skip SQL until the model is created and remembered.

### Explain a query decision

```php
$explanations = IdentityMap::explain(fn () => User::whereKey([1, 2, 3])->where('active', true)->get());
// Plan: rewrite_predicate_and_merge
// Model: App\Models\User
// Reason: ...
// Known keys: [1, 2]      ← evaluated in-memory (match or reject)
// Missing keys: [3]       ← unknown, sent to database
// SQL executed: yes
```

For more detail on the fields returned, see [Observability: explain()](#observability-explain).

### Supported in-memory predicates

The following are evaluated against cached attributes without touching the database. They apply both as extra conditions on top of a key-set or unique-key query, and as the basis for determining whether a unique-key candidate matches:

| Eloquent method | Operators |
|---|---|
| `where($col, $val)` / `where($col, '=', $val)` | `=` |
| `where($col, '!=', $val)` / `where($col, '<>', $val)` | `!=`, `<>` |
| `where($col, '>', $val)`, `>=`, `<`, `<=` | `>`, `>=`, `<`, `<=` |
| `whereIn($col, [...])` | `IN` |
| `whereNotIn($col, [...])` | `NOT IN` |
| `whereNull($col)` | `IS NULL` |
| `whereNotNull($col)` | `IS NOT NULL` |
| `whereBetween($col, [$min, $max])` | `BETWEEN` |
| `whereNotBetween($col, [$min, $max])` | `NOT BETWEEN` |
| Multiple `where` chained with `AND` | AND-tree |

Anything the package cannot evaluate in memory falls through to SQL unchanged — unsupported operators (`LIKE`, `ILIKE`), raw `whereRaw` clauses, `orWhere` conditions, and attributes not present on a partially loaded model. String comparisons may also resolve to `Unknown` (and fall through) depending on the configured [driver semantics](#driver-semantics-database_semantics). See [Predicate evaluation](#predicate-evaluation) for how the engine decides.

### Relation optimizations

When `HasIdentityMap` is applied, five relation types gain memory-backed implementations. They fall back to SQL transparently on any condition the package cannot safely evaluate in memory.

**`belongsTo` / `morphTo`** — resolved without SQL when the related model is already in the identity map:

```php
$post->user;       // no SQL if User#N is already in the map
$comment->owner;   // no SQL for polymorphic relations too
```

Fallback to SQL when: FK is null, the related model class does not use `HasIdentityMap`, the entry is absent from the map, or the query has joins, unions, groups, havings, or a lock.

**`hasMany` / `morphMany`** — when the parent's relation is already completely loaded, additional `where` constraints are evaluated in memory:

```php
$user->load('posts');                                    // marks the relation complete in the map

$user->posts()->get();                                   // no SQL — full cached collection
$user->posts()->where('published', true)->get();         // no SQL — filtered in memory
```

Fallback to SQL when: the relation has not been loaded, the relation was loaded with extra constraints (constrained eager load), the query adds joins/unions/groups/havings/lock/offset/limit, the predicate cannot be evaluated in memory, or any member of the loaded collection has left the identity map.

**`belongsToMany`** — many-to-many traversal is served from the identity graph when both sides are mapped and the pivot edges have been recorded. The graph stores `(parent, relation, related)` pivot edges, including pivot column values, so that `wherePivot`-style filters and basic predicates on the related model can be evaluated in memory:

```php
$user->load('roles');                                    // pivot edges + related models recorded

$user->roles;                                            // no SQL — served from the graph
$user->roles()->wherePivot('granted', true)->get();      // no SQL — pivot predicate evaluated in memory
```

Fallback to SQL when: the related model does not use `HasIdentityMap`, pivot coverage for the parent's `(class, id, relation)` slot has not been recorded, the query touches custom pivot accessors not present in the captured pivot columns, the query has joins/unions/groups/havings/lock/offset/limit, the relation uses `wherePivotIn` / `wherePivotNull` on columns not extractable to a predicate node, or the relation-graph cap is hit (config `relation_graph.max_edges` / `max_coverage_entries`). Disable the graph entirely with `IDENTITY_MAP_RELATION_GRAPH_ENABLED=false` if you suspect a bug.

See [Architecture and internals](#architecture-and-internals) for how the memory relation implementations work, and [Identity graph](#identity-graph-relation_graph) for the data structure backing `belongsToMany`.

---

## Architecture and internals

### High-level data flow

Every Eloquent query on a model that uses `HasIdentityMap` is routed through `IdentityMapBuilder` instead of the standard Eloquent builder. The builder delegates to `QueryPatternExtractor`, which classifies the incoming query into one of five shapes: single primary-key equality, bounded key-set `IN`, unique-key equality, full-predicate coverage candidate, or structural-hazard bypass.

For shapes the package can handle, it consults `IdentityMapStore` or `CoverageRegistry` under a scope fingerprint that isolates entries by soft-delete variant and active global scopes. If memory can fully answer the query, no SQL is issued. If memory can partially answer it (some keys known, some not), the query is rewritten to exclude the known portion and the two result sets are merged. If memory cannot safely answer it at all, the query falls through to SQL unmodified.

The decision order within the builder is: exact PK hit → absent-key short-circuit → key-set rewrite → unique-key index lookup → coverage region check → SQL execution with result remembering.

### Opt-in mechanism: HasIdentityMap

Applying the `HasIdentityMap` trait to a model makes five structural changes:

1. `newEloquentBuilder()` is overridden to return `IdentityMapBuilder` instead of the standard builder.
2. `newBelongsTo()` and `newMorphTo()` are overridden to return `MemoryBelongsTo` and `MemoryMorphTo`.
3. `newHasMany()` and `newMorphMany()` are overridden to return `MemoryHasMany` and `MemoryMorphMany`.
4. `newBelongsToMany()` is overridden to return `MemoryBelongsToMany`.
5. `bootHasIdentityMap()` registers model event listeners: `retrieved`, `saved`, `deleted` unconditionally, plus `restored` and `forceDeleted` when the model also uses `SoftDeletes`.

The trait is model-scoped. Models without it are never stored in or served from the map, and queries on those models are entirely unaffected.

### The store: IdentityMapStore and IdentityEntry

`IdentityMapStore` is a Laravel singleton. It holds two hash-maps: `$entries` for live model instances and `$absent` for confirmed-missing primary keys. Both are keyed by a compound string: `connection|modelClass|table|pkName|pkValue|scopeFingerprint`.

Each entry in `$entries` is an `IdentityEntry` containing:

- **model** — the actual Eloquent model instance.
- **AttributeKnowledge** — a per-column map of `AttributeFact` objects (original value, current value, dirty flag, confidence, source).
- **RelationKnowledge** — a record of which relations are fully loaded and the primary keys of their members.
- **LifecycleState** — `Exists`, `SoftDeleted`, or `Deleted`.
- **version** — an integer incremented on every update; used internally to detect whether a cached plan is still valid.

The absent map uses the same key format but stores only a `true` sentinel. When a query confirms that a key does not exist in the database, that key is added to `$absent`. Subsequent identical lookups return `null` (or are excluded from results) without touching the database.

### Scope isolation: ScopeFingerprinter

Every entry in the store is namespaced by a scope fingerprint so that models retrieved under different query conditions never cross-contaminate. The fingerprinter captures three dimensions:

- **Soft-delete variant** — three slots: the default scope (excludes trashed), `withTrashed()`, and `onlyTrashed()`. A `User::find(1)` and a `User::withTrashed()->find(1)` produce different fingerprints and are stored separately.
- **Extra global scopes** — any additional global scopes applied to the model are hashed into the fingerprint.
- **Connection name** — already part of the composite key, making multi-database setups safe by default.

This fingerprinting is what makes the package safe under Laravel Octane: two concurrent requests may share the same PHP process, but they execute under different scopes and will always produce distinct fingerprints. The store is flushed between requests anyway, but the fingerprinting provides an additional layer of isolation.

### Query classification: QueryPatternExtractor

`QueryPatternExtractor` analyses the query's WHERE clauses, joins, groups, havings, locks, and SELECT list before any memory lookup is attempted. It classifies the query into one of five shapes:

1. **Single PK equality** — `WHERE id = ?` with no other constraints the package cannot evaluate.
2. **Bounded key-set IN** — `WHERE id IN (?, ?, ...)` optionally with additional AND predicates.
3. **Unique-key equality** — `WHERE email = ?` (or a compound unique key) where all columns in one of the configured unique indexes are present as equality conditions.
4. **Coverage candidate** — a predicate-only WHERE clause with no key constraints; eligible for the coverage registry.
5. **Structural-hazard bypass** — anything else: the query falls straight through to SQL.

Structural hazards that trigger bypass include: joins, unions, `GROUP BY`, `HAVING`, pessimistic locks (`lockForUpdate`, `sharedLock`), non-string SELECT columns introduced by `withCount` or `selectRaw`, and `orWhere` clauses. The extractor is stateless and pure — it only reads the query object, never modifying it.

### Predicate evaluation

When the extractor identifies predicates that should be evaluated in memory, `PredicateExtractor` converts the Eloquent WHERE clause list into a typed tree. The tree has five node types:

- **`AndNode`** — a list of child nodes that must all evaluate to `Match`.
- **`ComparisonNode`** — a single column/operator/value triple; supports `=`, `!=`, `<>`, `>`, `>=`, `<`, `<=`.
- **`InNode`** — `whereIn` (positive) or `whereNotIn` (negated).
- **`NullNode`** — `whereNull` or `whereNotNull`.
- **`BetweenNode`** — `whereBetween` (positive) or `whereNotBetween` (negated).

`PredicateEvaluator` walks the tree against the `AttributeKnowledge` of a cached entry and returns one of three results:

- **`Match`** — the entry satisfies all conditions; it can be returned from memory.
- **`Reject`** — at least one condition is definitely false; the entry can be excluded without SQL.
- **`Unknown`** — the entry does not have a known value for a required attribute, or an operator is not supported. The entry is excluded from the memory path and its key is forwarded to the SQL query.

`Unknown` is the safe fallback. It never produces a wrong answer; it produces a SQL query. An entry with only partial attributes loaded — for example, from a `select('id', 'name')` query — will return `Unknown` for any predicate on a column outside that select list, and the key will be re-fetched.

Under `process_truth` mode, the evaluator uses the current in-memory attribute value (which may be dirty) instead of the original database-committed value. This is the only mode in which the package can return results that differ from what the database currently contains.

### Attribute knowledge

`AttributeKnowledge` tracks what the package knows about a model's columns. For each column that has been observed, it stores an `AttributeFact` containing:

- **originalValue** — the value as it was last committed to the database (or hydrated from it).
- **currentValue** — the value currently on the model instance, which may differ if the model is dirty.
- **isDirty** — whether the two values differ.
- **confidence** — `Certain` (hydrated from a `SELECT *` or explicitly confirmed) or `Assumed` (inferred from a partial select or a mass-write plan).
- **source** — where the fact came from. One of the `FactSource` enum cases:
  - `HydratedFromDatabase` — read directly from a `SELECT` result.
  - `AssignedInMemory` — user code assigned the attribute on the model instance.
  - `CastedModelAttribute` — value reflects an Eloquent cast applied during hydration.
  - `AppendedAttribute` — value comes from a model accessor in `$appends`.
  - `RelationDerived` — value was inferred from a related model (e.g. a foreign key set when a relation was assigned).
  - `MassWrite` — value was written by a bulk `update()` whose predicate matched the entry.
  - `Unknown` — fact source could not be classified.

The `allColumnsKnown` flag is set when a model is hydrated from a full-row query. When it is `false`, the predicate evaluator will return `Unknown` for any column not present in the fact map, preventing the package from returning a stale partial model in place of a full-row result.

### Unique-key index

`UniqueKeyIndex` is a secondary hash-map inside the store, keyed on a fingerprint of `connection|class|table|scopeFingerprint|sorted(column→value)`. It enables `where('email', '...')->first()` style queries to be answered from memory without a linear scan of all entries.

When a model is remembered by the store, its attribute facts are cross-referenced against the unique column sets declared in config. For each declared unique set where all columns are known and `Certain`, an entry is added to the unique-key index pointing at the primary key of the cached model.

Stale index entries are detected at lookup time: if the primary-key entry retrieved via the index no longer has matching attribute values (because the column was updated), the index entry is discarded and the lookup falls through to SQL.

Under `process_truth` mode, the unique-key index is bypassed entirely. The index is built on original (committed) values; querying against dirty values via the index would produce incorrect results.

### Coverage: CoverageRegistry and SubsetChecker

`CoverageRegistry` tracks entire query regions that have been fully resolved. After a SQL query whose shape and predicate are safe for caching, the registry stores a `CoverageEntry` recording: the predicate region (the AND-tree of conditions), the set of primary keys returned, the column set loaded, and the scope fingerprint.

When a subsequent query arrives, `SubsetChecker` tests whether the new query's predicate region is provably a subset of a recorded coverage region. If the new conditions are strictly narrower — every condition in the new query is also present in the recorded region, or adds further restrictions — then the registered primary keys are re-evaluated against the new predicate using `PredicateEvaluator`, and the result is assembled from the in-memory entries for those keys. No SQL is issued.

Coverage entries are invalidated conservatively. When a model is saved, the registry flushes any coverage entries whose predicate region references columns that were changed. When a model is created or deleted, the entire coverage for that model class is flushed, since a new row could fall into any previously-recorded region.

### Process-truth vs database-truth

The `mode` config key controls a single behavioural switch: whether dirty in-memory attributes affect predicate evaluation.

| Mode | What it does | Default? |
|---|---|---|
| `default` | Predicates evaluate against the last-committed (original) attribute value. Dirty mutations are ignored until `save()`. In-memory results always match a fresh `SELECT`. | **yes** |
| `process_truth` | Predicates evaluate against the current in-memory value, which may be dirty. The unique-key index path is bypassed under this mode, since the index is keyed on original values. | |

`mode = process_truth` is the only setting that can make memory-served results differ from what `SELECT` would return on a fresh connection. Use it when your workload expects assigned-but-unsaved attribute writes to be visible to downstream queries within the same request.

The `IDENTITY_MAP_MODE` environment variable may be used to override the config value without republishing.

The set of optimizations the package performs — primary-key reuse, key-set rewriting, unique-key lookup, coverage, the relation graph, and `whereHas` rewriting — is not individually configurable; they are always on. Disable per query with `->withoutIdentityMap()` or per scope with `IdentityMap::disabled(...)`.

#### Upgrade note: `attribute_truth` → `mode`

Earlier pre-1.0 builds read the toggle from a config key named `attribute_truth` (env var `IDENTITY_MAP_ATTRIBUTE_TRUTH`) with values `database_only` / `process_truth`. That key never appeared in the published config file, so most installs never set it. It has been renamed to `mode` (env var `IDENTITY_MAP_MODE`) with values `default` / `process_truth`.

The old key is **not** read anymore: installs that still have `attribute_truth` set will silently fall back to the new default (`mode = default`). To retain previous behaviour:

| Old | New |
|---|---|
| `'attribute_truth' => 'database_only'` (or unset) | `'mode' => 'default'` (or unset) |
| `'attribute_truth' => 'process_truth'` | `'mode' => 'process_truth'` |
| `IDENTITY_MAP_ATTRIBUTE_TRUTH=process_truth` | `IDENTITY_MAP_MODE=process_truth` |

If you published the config, re-publish (or delete `attribute_truth` and add `mode`) and update any `.env` references. If you never published the config, only the environment variable rename matters.

### Identity graph (`relation_graph`)

`IdentityGraph` records `(parent, relation, related)` edges between mapped models so that relation queries can be answered from memory when the package has seen enough of the structure. It supports:

- **Plain `RelationEdge`** entries for `belongsTo` / `hasMany` / `morphMany` / `morphTo`, captured each time a relation is hydrated.
- **`PivotEdge`** entries for `belongsToMany`, including the captured pivot column values so that `wherePivot()`-style filters can be evaluated against the graph instead of the pivot table.
- **`RelationCoverage`** / **`PivotCoverage`** markers that record "this parent's relation is fully loaded" so subsequent `$user->roles` reads can be served without SQL.

The graph powers the `where_has_from_graph`, `where_doesnt_have_from_graph`, `belongs_to_many_from_graph`, and `where_pivot_in_memory` plans. It is invalidated per-model on `saved` (for the changed model's identity) and per-class on creation, deletion, and rolled-back transactions touching that class.

| Config key | Default | Env override | Effect |
|---|---|---|---|
| `relation_graph.enabled` | `true` | `IDENTITY_MAP_RELATION_GRAPH_ENABLED` | Disable to bypass all graph-based plans; relation traversal falls back to per-relation memory paths or SQL. |
| `relation_graph.max_edges` | `50000` | `IDENTITY_MAP_RELATION_GRAPH_MAX_EDGES` | When exceeded, the graph flushes entirely (safest behaviour). |
| `relation_graph.max_coverage_entries` | `5000` | `IDENTITY_MAP_RELATION_GRAPH_MAX_COVERAGE` | When exceeded, the graph flushes entirely. |

### Partial models & column backfill (`partial_models`)

When a cached entry was loaded with a narrow `select(['id', 'name'])` and a later query asks for additional columns, the package can either re-run the original query (default) or issue a small `SELECT only_missing_columns FROM table WHERE id = ?` and merge the result into the cached instance.

| Value | Behaviour |
|---|---|
| `query_normally` *(default)* | Cache miss → execute the full original query. Safe, equivalent to having no backfill. |
| `backfill_missing_columns` | Cache hit on the primary key but missing some requested columns → issue a narrow backfill SELECT, merge into the cached model, return from memory. Dirty in-memory attributes are preserved (only `originalValue` is updated for those columns). |

Backfill fires only for point lookups: `find()`, unique-key lookups, and `MemoryBelongsTo`. Coverage paths and `whereHas` rewrites still fall through to a full `SELECT` when columns are missing. Each backfill emits a `backfill_columns_from_database` explanation with `sqlExecuted: true`.

Override via the `IDENTITY_MAP_PARTIAL_MODELS` environment variable.

### Schema discovery (`schema_discovery`)

`SchemaDiscovery` inspects each model's table on first use via `Schema::getIndexes()` / `Schema::getColumns()` and feeds the result into both the unique-key index and the per-column driver semantics. Config-declared unique indexes (under `models.{ClassName}.unique`) take precedence; discovered indexes supplement them.

| Config key | Default | Env override |
|---|---|---|
| `schema_discovery.enabled` | `true` | `IDENTITY_MAP_SCHEMA_DISCOVERY` |

Discovery results are cached on the singleton resolver and flushed on the same scope boundaries as the store (request termination, job processed/failed, scope flush). Disable it (`IDENTITY_MAP_SCHEMA_DISCOVERY=false`) if your DB driver does not expose index metadata in a way Laravel can read, or if you want to lock the package to only the unique sets declared in config.

### Driver semantics (`database_semantics`)

The predicate evaluator resolves comparisons through a per-connection `DriverSemantics` (one of `SqliteSemantics`, `MySqlSemantics`, `MariaDbSemantics`, `PostgresSemantics`, or `ConservativeSemantics`). Integer, boolean, UUID, and null comparisons are always resolved confidently. The `database_semantics.{driver}.string_comparisons` config key controls how string equality is handled:

| Value | Behaviour |
|---|---|
| `database_collation` *(default)* | Read the column collation reported by `Schema::getColumns()` and compare under that collation. Falls back to the driver default — case-sensitive for SQLite/Postgres, Unknown for MySQL/MariaDB — when the collation is missing. |
| `php_strict` | Treat every string column as case-sensitive byte-equality. Fast, but **wrong** on MySQL with case-insensitive collations: in-memory results will diverge from SQL. |
| `conservative_unknown` | Return `Unknown` for every string comparison and let SQL handle it. Maximally safe but eliminates most string-predicate elision. |

Each driver has its own env var (`IDENTITY_MAP_SQLITE_STRING_COMPARISONS`, `IDENTITY_MAP_MYSQL_STRING_COMPARISONS`, `IDENTITY_MAP_MARIADB_STRING_COMPARISONS`, `IDENTITY_MAP_PGSQL_STRING_COMPARISONS`). Set them when your MySQL deployment uses a case-insensitive collation (`utf8mb4_unicode_ci`, `utf8mb4_general_ci`, etc.) and you observe predicate-evaluation mismatches.

### Lifecycle hooks and automatic flushing

The store and coverage registry are flushed automatically at scope boundaries to prevent stale data from leaking between independent units of work.

**HTTP requests** — the store is flushed via `app()->terminating()` when the response is sent. Models hydrated during one request are never visible to the next.

**Queue jobs** — the store is flushed before and after each job via the `JobProcessing`, `JobProcessed`, and `JobFailed` events. This applies to both traditional queue workers and Octane workers processing jobs. A crashed job does not leave stale entries visible to the next job.

**Database transaction rollback** — a per-connection `TransactionJournal` snapshots the pre-modification state of any map entry touched inside a transaction. On `TransactionRolledBack` the journal **restores** those snapshots into the store, so cached attributes return to their pre-transaction values rather than reflecting rows that no longer exist. Coverage and graph entries for any model class touched in the rolled-back level are invalidated. Nested savepoints stack: rolling back an inner savepoint restores only that level; an outer rollback restores everything inherited by the parent. If a rollback fires without a matching tracked `begin()` — for example, when the package boots mid-transaction — the store, coverage registry, and identity graph are flushed entirely as a safe fallback.

**Model events** — within a scope, three to five model events drive incremental updates rather than full flushes (three by default; five when the model uses `SoftDeletes`):

| Event | Action |
|---|---|
| `retrieved` | Model added to store with all currently-known attributes. |
| `saved` | Cached attributes updated to match committed values; coverage flushed for changed columns (or for the whole class on creation). |
| `deleted` | Entry marked `Deleted`; coverage for the model class flushed. |
| `restored` *(SoftDeletes only)* | Treated as a save; coverage for the model class flushed. |
| `forceDeleted` *(SoftDeletes only)* | Entry removed from store entirely. |

### Mass writes

Bulk `->update([...])` and `->delete()` calls on a builder — those that affect multiple rows at once — also update the in-memory store rather than leaving it stale.

After the SQL executes, the package evaluates the builder's predicate against every cached entry for the affected model class:

- **`Match`** — the entry's attribute facts are updated to reflect the written values (for `update`) or the entry is marked `SoftDeleted`/`Deleted` (for `delete`).
- **`Reject`** — the entry is definitely outside the affected set; left unchanged.
- **`Unknown`** — the entry cannot be safely classified; it is evicted from the store so a future query will re-fetch it from the database.

Eviction triggers a coverage flush for the model class, since any previously-recorded query region may now be incomplete.

### Observability: explain()

`IdentityMap::explain(Closure $fn)` wraps a block of code and returns a list of `Explanation` objects — one per query that the package considered. Each object contains:

| Field | Type | Description |
|---|---|---|
| `type` | `PlanType` | The decision the package made (see table below). |
| `modelClass` | `string` | Fully-qualified model class name. |
| `reason` | `string` | Human-readable explanation of why this plan was chosen. |
| `sqlExecuted` | `bool` | Whether a SQL query was issued. |
| `knownKeys` | `list` | Primary keys that were found in the store. |
| `missingKeys` | `list` | Primary keys confirmed absent. |
| `memoryKeys` | `list` | Keys answered from memory (subset of knownKeys after predicate evaluation). |
| `rejectedKeys` | `list` | Keys whose predicate evaluated to Reject. |
| `coverageRegion` | `?string` | String representation of the coverage region, when applicable. |

`PlanType` values:

| Value | Meaning |
|---|---|
| `execute_normally` | Query fell through to SQL unchanged (structural hazard or no memory data available). |
| `return_model_from_memory` | Single model returned from the store without SQL. |
| `return_null` | Known-absent key; returned `null` without SQL. |
| `return_collection_from_memory` | Entire collection returned from the store without SQL. |
| `return_empty_collection` | All keys were absent; returned empty collection without SQL. |
| `rewrite_primary_keys_and_merge` | Query rewritten to exclude known keys; SQL result merged with memory result. |
| `rewrite_predicate_and_merge` | As above, with predicate evaluation applied to known keys before merge. |
| `return_scalar_from_memory` | `value()` call answered from a cached attribute. |
| `return_exists_from_memory` | `exists()` call answered from a cached entry or absent record. |
| `return_count_from_coverage` | `count()` answered from coverage registry. |
| `return_belongs_to_from_memory` | `belongsTo` / `morphTo` relation resolved from the store. |
| `filter_has_many_in_memory` | `hasMany` / `morphMany` relation filtered from the store. |
| `return_collection_from_coverage` | `get()` answered from coverage registry. |
| `return_exists_from_coverage` | `exists()` answered from coverage registry. |
| `return_pluck_from_coverage` | `pluck()` answered from coverage registry. |
| `return_first_from_coverage` | `first()` answered from coverage registry. |
| `return_sole_from_coverage` | `sole()` answered from coverage registry. |
| `return_sum_from_coverage` | `sum()` aggregate answered from coverage registry. |
| `return_min_from_coverage` | `min()` aggregate answered from coverage registry. |
| `return_max_from_coverage` | `max()` aggregate answered from coverage registry. |
| `return_avg_from_coverage` | `avg()` / `average()` aggregate answered from coverage registry. |
| `where_has_from_graph` | `whereHas()` rewritten to a parent-key IN clause using the identity graph. |
| `where_doesnt_have_from_graph` | `whereDoesntHave()` rewritten using the identity graph. |
| `belongs_to_many_from_graph` | `belongsToMany` relation served from the identity graph (no SQL). |
| `where_pivot_in_memory` | `belongsToMany` query filtered in memory via captured pivot columns. |
| `backfill_columns_from_database` | Narrow `SELECT` issued to backfill missing columns on a cached entry (see [Partial models & column backfill](#partial-models--column-backfill-partial_models)). |

`Explanation::__toString()` renders a short summary suitable for logging:

```
Plan: rewrite_predicate_and_merge
Model: App\Models\User
Reason: ...
Known keys: [1, 2]
Missing keys: [3]
SQL executed: yes
```

---

## Test suite

### Overview

The package has six test layers, each targeting a distinct risk surface. Together they provide defence in depth: logic bugs, integration failures, configuration-space gaps, random edge cases, and performance regressions are all caught by different layers — often before any other layer would notice them.

| Suite | Command | Included in CI |
|---|---|---|
| Unit + Feature + DataProviders | `composer test` | Yes |
| Performance | `vendor/bin/phpunit --testsuite Performance` | Yes (Bencher) |
| Fuzz | `composer fuzz` | Yes (`comprehensive` job, 4-DB matrix) |
| Mutation | `composer mutate` | Yes (Stryker dashboard) |

CI runs the full matrix: PHP 8.3, 8.4, 8.5 × Laravel 11, 12, 13 × SQLite, MySQL, MariaDB, PostgreSQL — 36 cells total.

### Unit tests

**Location:** `tests/Unit/` (see directory for the current set)  
**Extends:** `PHPUnit\Framework\TestCase` — no database, no service container, no Laravel boot.

The unit suite tests the pure algorithms in isolation:

- `PredicateEvaluatorTest` — all three return values (`Match`, `Reject`, `Unknown`) for every supported operator and node type; AND-tree short-circuit behaviour; `process_truth` vs original-value routing.
- `AttributeKnowledgeTest` — `satisfies()` logic for full vs partial column knowledge; `recordFromModel()` and `mergeFromSaved()` behaviour.
- `PredicateExtractorTest` — conversion of Eloquent WHERE clause arrays into the typed node tree.
- `PredicateColumnsTest` — column set extraction from node trees.
- `CoverageRegistryTest` — `flushByColumns()` correctly invalidates only relevant entries; `isSubset()` for all supported predicate pair shapes.
- `RelationKnowledgeTest`, `RelationFactTest` — relation metadata tracking and fact structure.
- `ColumnSetTest`, `SubsetCheckerTest` — column set operations and subset checking logic.
- `ExplanationTest` — `Explanation` struct formatting and `__toString()` output.

The unit suite is the fastest feedback loop. Any regression in the pure core — a wrong return value from the evaluator, a broken column set operation — is caught here in isolation before an integration test is even needed.

### Feature tests

**Location:** `tests/Feature/` (see directory for the current set, organised into subdirectories per subsystem)  
**Extends:** `Vusys\QueryRicerExtreme\Tests\TestCase` (Orchestra Testbench + SQLite)

The feature suite tests end-to-end behaviour with a real database and real Eloquent model lifecycle. It uses a fixed set of test models: `User` (with `HasIdentityMap` and `SoftDeletes`), `Post`, `Tag`, `Comment` (polymorphic morph), and `UuidUser` (UUID primary key).

Core files:

- `IdentityMapTest` — main builder path: `find()` caching and instance identity, absent-key tracking, `withoutIdentityMap()`, soft-delete scope separation, queries with joins/locks/aggregates that must bypass the map, `flush()` and `forget()`, the `explain()` API.
- `KeySetRewriteTest` — partial-hit rewriting: queries where some keys are in memory and some require SQL, with correct ordering and instance identity in the merged result.
- `BelongsToMemoryTest`, `HasManyMemoryTest`, `MorphManyMemoryTest`, `MorphToMemoryTest` — one file per relation type, each verifying memory resolution and SQL fallback conditions.
- `PredicateEvaluatorFeatureTest` — predicate evaluation integrated with real model hydration and Eloquent query building.
- `ProcessTruthTest` — dirty attribute changes evaluated correctly under `process_truth` mode.
- `MassWriteModelingTest` — bulk `update()` and `delete()` correctly propagate to the in-memory store.
- `UniqueKeyTest` — unique-key index lookups, absence tracking by unique value, compound keys.
- `QueryPatternExtractorTest`, `ScopeFingerprinterTest`, `CoverageRegistryFeatureTest`, `ServiceProviderTest` — coverage of remaining subsystems.

The feature suite verifies that the package integrates correctly with Eloquent hydration, global scopes, model events, and the soft-delete system. Most tests assert SQL query count explicitly (via `DB::getQueryLog()`) to confirm that memory hits genuinely avoid the database.

### Cartesian / data-provider tests

**Location:** `tests/Feature/DataProviders/` (5 files)  
**Extends:** Same TestCase as feature tests; uses `ProvidesCartesian` concern.

These tests use PHPUnit data providers to generate the Cartesian product of multiple dimension arrays, running each combination as a separate test case. This brute-forces coverage of the configuration space without hand-writing exponentially many test methods.

| File | Dimensions covered |
|---|---|
| `ConfigPermutationTest` | Unique-key config shapes (none / single-column / compound / multi-index) crossed with lookup methods and absence-tracking paths |
| `PkTypeTest` | Integer and UUID primary key types |
| `LifecycleStateTest` | All three `LifecycleState` values (Exists, SoftDeleted, Deleted) |
| `WhereShapeTest` | Supported and unsupported WHERE operators; qualified vs unqualified column names; safe scoped queries |
| `KeySetShapeTest` | Full hit, partial hit, no hit, empty key-set |

The Cartesian suite catches bugs that only surface in specific combinations — for example, a predicate evaluation error that only appears when using UUID keys under `process_truth` mode with a `whereNotIn` condition. Those interactions are invisible to hand-written tests but explicit in a Cartesian product.

### Fuzz tests

**Location:** `tests/Fuzz/` (3 test files)  
**Command:** `composer fuzz` (PHPUnit group `fuzzer`)  
**CI:** runs in the `comprehensive` job against all four database backends

The fuzz suite uses seeded randomness so failures are reproducible. Each test method runs across multiple seeds × steps (default 3 × 20 = 60 iterations per method). When a test fails, the output includes `[seed=N step=M]`. Exact replay: `FUZZER_SEEDS=N FUZZER_STEPS=$((M+1)) composer fuzz`.

**`QueryCorrectnessTest`** — differential (oracle) testing. Runs the same query through both the identity-map path and `IdentityMap::disabled()`, then asserts the two results are identical. Five methods:

- `test_find_by_primary_key_matches_oracle` — `find()` with 60/40 known/absent key ratio.
- `test_where_key_collection_matches_oracle` — `whereKey()->get()` with random key subset and guaranteed-unknown IDs mixed in.
- `test_active_predicate_via_key_set_matches_oracle` — `whereKey()->where('active', ...)->get()` with partial warm state and predicate pruning.
- `test_where_has_with_graph_coverage_matches_oracle` — `whereHas('posts', …)` rewritten against the identity graph must match the bypassed query.
- `test_where_doesnt_have_matches_oracle` — `whereDoesntHave` rewrite inverts membership semantics correctly.

**`QuerySavingsTest`** — property-based SQL-count testing. Rather than checking equivalence, it asserts that the identity-map path fires *fewer* SQL queries than the oracle. Four methods:

- `test_find_warm_entry_fires_no_sql` — `find()` on an already-cached ID must issue 0 SQL (oracle: 1).
- `test_where_key_all_warm_fires_no_sql` — `whereKey()` on a fully-warm key set must issue 0 SQL (oracle: 1).
- `test_absent_tracking_fires_no_sql_on_repeat` — a second `find()` on a confirmed-absent ID must issue 0 SQL.
- `test_where_has_with_full_graph_coverage_fires_no_sql` — `whereHas` with complete graph coverage must answer from memory.

**`RelationalCorrectnessTest`** — dual-database relational correctness. Uses a secondary isolated database connection as the oracle so relation traversal and write→read consistency are verified against a real second database, not just the disabled-flag path. Four methods:

- `test_keyset_reads_match_oracle` — partial-hit keyset reads via `whereKey()->get()`.
- `test_relation_traversal_matches_oracle` — `user→posts→tag` and `user→comments` relation chains.
- `test_mutation_read_consistency_matches_oracle` — save/delete/restore then re-read; detects stale-cache bugs the oracle would expose as a mismatch.
- `test_pivot_mutation_read_consistency_matches_oracle` — `belongsToMany` pivot mutations (attach/detach/sync/updateExistingPivot) followed by re-reads must match the oracle.

Together the three files cover two orthogonal properties — *correctness* (results match the database) and *savings* (SQL count is reduced) — and extend correctness testing to relation traversal and write consistency across every supported DB engine.

### Performance tests

**Location:** `tests/Performance/` (1 file)  
**Command:** `vendor/bin/phpunit --testsuite Performance` (separate suite, not run by `composer test`)

The performance suite measures wall-clock time and SQL query count, not functional correctness. Results are emitted to STDERR in a Bencher-compatible format and tracked for regression via the Bencher CI badge in the header.

Three benchmarks run 100 iterations each:

| Benchmark | Expected SQL queries |
|---|---|
| Repeated `find()` on a known ID with the identity map | 1 (first load only) |
| Repeated `find()` on an absent ID with absence tracking | 1 (first miss recorded) |
| Repeated `find()` with `withoutIdentityMap()` as baseline | 100 |

The performance suite catches query-count regressions: a code change that accidentally stops the cache from being consulted will produce 100 SQL queries instead of 1, which the suite will flag even if every correctness test still passes.

### Mutation testing

**Command:** `composer mutate` (runs Infection with 4 threads)  
**Results:** `build/infection/summary.log`, `build/infection/infection.log`  
**Dashboard:** Stryker badge in the header

Infection mutates the source code one change at a time and checks whether the test suite kills each mutant (i.e., at least one test fails). A surviving mutant means a line of code can be changed without any test noticing — which usually indicates either dead code or an under-specified test.

Intentional exclusions are documented in `infection.json5`. The suppressions fall into two categories:

- **False positives** — mutations that are behaviourally equivalent given the package's invariants (e.g. the `version++` counter, which no test observes directly because it is an internal staleness token).
- **Architectural limits** — mutations that escape because the internal data structure required to kill them is not accessible from the test layer (e.g. a logical condition in `IdentityMapBuilder::getModels()` where the only distinguishing state is inside the absent map).

The mutation suite validates that the correctness tests are actually discriminating, not merely achieving line coverage. A high mutation score means a change to a predicate condition or a return value will be caught; it does not guarantee correctness in the absence of a failing test, but it significantly raises the bar.

### How the suites complement each other

| Suite | Catches | Does not catch |
|---|---|---|
| Unit | Logic bugs in pure algorithms; wrong return values from the predicate evaluator | Integration failures; database-specific behaviour |
| Feature | Eloquent integration bugs; soft-delete scope separation; event wiring | Configuration-space combinations; random edge cases |
| Cartesian | Mode/type/operator combination bugs invisible to hand-written tests | Random-state edge cases; performance regression |
| Fuzz | Behavioral divergence from SQL baseline under random model state | Deterministic bugs; performance regression |
| Performance | Query-count regression; wall-time degradation | Functional correctness of any kind |
| Mutation | Under-specified tests; lines that can be changed without a failure | Everything above (it validates test quality, not code quality) |

A correctness test suite that fully passes can still mask a query-count regression — only the performance suite catches that. A deterministic test suite that fully passes can still miss a rare state combination — only the fuzz suite catches that. The layers are designed to be non-overlapping in what they can miss.

---

## Publish config

```bash
php artisan vendor:publish --tag=query-ricer-extreme-config
```

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## License

MIT
