# Roadmap

Working document for agents/contributors. Items are ordered — work top to bottom within a phase, and do not start a later phase while an earlier phase has open correctness items. Each item lists the motivation, the relevant code, and acceptance criteria.

Ground rules for every item (see `CLAUDE.md`):

- `composer test`, `composer analyse`, `composer pint:check`, `composer rector:check` must all pass before a change is considered done.
- Every behavioural fix lands with a regression test that fails before the fix.
- The package's prime directive: **a memory-served answer must be indistinguishable from what SQL would have returned** (except under opt-in `process_truth`). When in doubt, bail out to SQL — a missed optimization is fine, a divergent result is not.

---

## Phase 1 — Correctness fixes (blockers; do these first)

These are confirmed, reproduced divergences between memory-served results and SQL. Each repro below was run against the current codebase and fails.

### 1.1 `ORDER BY` is ignored on memory-serving paths

The only code that consults `$query->orders` is `sortForFirst()` (`src/Query/IdentityMapBuilder.php:1838`), which only serves `first()`. Three other paths return rows in the wrong order when the caller specifies an explicit `orderBy`:

| Path | Where the hazard check is missing |
|---|---|
| Warm key-set `whereKey([...])->orderBy(...)->get()` | `QueryPatternExtractor::extractBoundedKeySet()` (`src/Query/QueryPatternExtractor.php:156`) — checks limit/offset but never `$query->orders`; results merged via `mergeByInputOrder()` in input-key order |
| Coverage-served `get()` / `pluck()` | `QueryPatternExtractor::isSafeForCoverage()` (`src/Query/QueryPatternExtractor.php:451`) — checks distinct/limit/offset/groups/havings/unions but not orders. A region recorded by an unordered query serves a later ordered query in recorded order |
| Loaded-relation memory filtering | `queryHasHazards()` in `src/Relations/MemoryHasMany.php:354`, `src/Relations/MemoryMorphMany.php` (same shape), and `MemoryBelongsToMany::queryHasHazards()` (`src/Relations/MemoryBelongsToMany.php:867`) — none treat `orders` as a hazard |

**Fix (two stages):**

1. *Safe bail-out (do this first, small diff):* treat non-empty `$query->orders` as a structural hazard in all three places above. Note `sortForFirst()` already handles the `first()` case for numeric columns — keep that, but make sure the `get()`-shaped paths bail.
2. *Optional follow-up (may be deferred to Phase 3.1):* sort in memory instead of bailing, using the existing `DriverSemantics` / `ColumnSemantics` machinery so collation and null-ordering match the database. Until then, bailing is correct.

**Regression tests** (these exact tests were run and currently fail — adapt into `tests/Feature/OrderByTest.php`):

```php
public function test_warm_key_set_respects_order_by(): void
{
    $a = User::factory()->create(['name' => 'Charlie']);
    $b = User::factory()->create(['name' => 'Alice']);
    $c = User::factory()->create(['name' => 'Bob']);

    User::find($a->id);
    User::find($b->id);
    User::find($c->id);

    $ricer = User::whereKey([$a->id, $b->id, $c->id])->orderBy('name')->get()->pluck('name')->all();
    $oracle = IdentityMap::disabled(
        fn () => User::whereKey([$a->id, $b->id, $c->id])->orderBy('name')->get()->pluck('name')->all()
    );

    $this->assertSame($oracle, $ricer);
}

public function test_coverage_served_get_respects_order_by(): void
{
    User::factory()->create(['name' => 'Charlie', 'active' => true]);
    User::factory()->create(['name' => 'Alice', 'active' => true]);
    User::factory()->create(['name' => 'Bob', 'active' => true]);

    User::where('active', true)->get(); // record coverage

    $ricer = User::where('active', true)->orderBy('name')->get()->pluck('name')->all();
    $oracle = IdentityMap::disabled(
        fn () => User::where('active', true)->orderBy('name')->get()->pluck('name')->all()
    );

    $this->assertSame($oracle, $ricer);
}

public function test_loaded_relation_respects_order_by(): void
{
    $user = User::factory()->create();
    $user->posts()->create(['title' => 'Charlie', 'published' => true]);
    $user->posts()->create(['title' => 'Alice', 'published' => true]);
    $user->posts()->create(['title' => 'Bob', 'published' => true]);

    $user->load('posts');

    $ricer = $user->posts()->orderBy('title')->get()->pluck('title')->all();
    $oracle = IdentityMap::disabled(
        fn () => $user->posts()->orderBy('title')->get()->pluck('title')->all()
    );

    $this->assertSame($oracle, $ricer);
}
```

Also add the equivalent `MemoryMorphMany` and `MemoryBelongsToMany` (`wherePivot` + `orderBy`) cases, plus a `latest()`/`oldest()` case since those are the idiomatic spellings.

**Acceptance:** all new tests pass; existing suite green; after the fix, queries with `orderBy` produce `execute_normally` plans (assert via `IdentityMap::explain()`).

### 1.2 `with()` eager loads dropped on `first()` / `sole()` coverage hits

`get()` is safe because Eloquent's `get()` calls `eagerLoadRelations()` after `getModels()`, and `find()` handles it explicitly (`src/Query/IdentityMapBuilder.php:294`). But the overridden `first()` (`IdentityMapBuilder.php:1216-1227`) and `sole()` (`IdentityMapBuilder.php:1278`) return the cached model from a coverage hit without applying `$this->eagerLoad`.

Consequences: `relationLoaded()` is false, relations missing from `toArray()` / API resources, and apps using `Model::preventLazyLoading()` throw `LazyLoadingViolationException` on attribute access — a hard break for strict-mode apps.

**Fix:** before returning from the coverage-hit branches in `first()` and `sole()`, mirror `find()`: `if ($this->eagerLoad !== []) { $this->eagerLoadRelations([$model]); }`. Audit every other early-return that hands back a model or collection without going through `get()` — at minimum check the `find()` absent path, unique-key paths inside `getModels()` (safe — `get()` wraps them), and the relation classes (their `get()` returns collections directly; check whether relation queries can carry eager loads via `with()` on the related query, e.g. `$user->posts()->with('tags')->get()` served from memory — if `$this->query->getEagerLoads() !== []` is not handled, treat it as a fallback condition).

**Regression test** (currently fails):

```php
public function test_with_first_applies_eager_load_on_coverage_hit(): void
{
    $user = User::factory()->create(['active' => true]);
    $user->posts()->create(['title' => 'P', 'published' => true]);

    User::where('active', true)->get(); // record coverage

    $served = User::with('posts')->where('active', true)->first();

    $this->assertNotNull($served);
    $this->assertTrue($served->relationLoaded('posts'));
}
```

Add variants for `sole()`, for nested eager loads (`with('posts.tags')`), and one running under `Model::preventLazyLoading()`.

**Acceptance:** tests pass; an `explain()` assertion confirms the plan is still a coverage hit (the fix must add the eager load, not give up the elision — the eager-load sub-queries themselves may hit the map).

### 1.3 Pivot predicate evaluation bypasses driver semantics

`MemoryBelongsToMany::evaluateSinglePivotNode()` (`src/Relations/MemoryBelongsToMany.php:577-650`) compares pivot attribute values with PHP loose `==`/`!=`, while the main `PredicateEvaluator` (`src/Predicate/PredicateEvaluator.php`) routes string comparisons through per-driver, collation-aware `ColumnSemantics`. Divergences:

- MySQL/MariaDB case-insensitive collations: SQL matches `'admin' = 'Admin'`; PHP `==` rejects → memory returns a wrongly-empty result.
- PHP numeric-string coercion: `'0123' == '123'` is true in PHP, false as a string comparison in SQL → memory wrongly matches.

**Fix:** route pivot comparisons through the same `DriverSemantics`/`ColumnSemanticsResolver` used by `PredicateEvaluator`, resolving column types/collations for the **pivot table** (schema discovery already inspects tables — extend it to the pivot table, or return `Unknown` for string-typed pivot comparisons when semantics cannot be resolved). `Unknown` → SQL fallback is acceptable; loose `==` is not.

**Regression test:** hard to provoke on SQLite default collation with pure PHP-vs-SQL string cases; add a unit test asserting string pivot comparisons resolve through semantics (or return `Unknown`), plus a feature test in the MySQL CI cell with a `utf8mb4_general_ci` pivot column where `wherePivot('role', 'ADMIN')` must match a stored `'admin'` both with and without the map. The fuzzers' pivot mutation test (`tests/Fuzz/RelationalCorrectnessTest.php::test_pivot_mutation_read_consistency_matches_oracle`) should also gain case-varied string pivot values.

**Acceptance:** no loose `==`/`!=` left in `MemoryBelongsToMany` predicate evaluation; MySQL/MariaDB CI cells green.

### 1.4 Fuzzer dimensions that would have caught 1.1–1.3

The fuzzers compare against an oracle but never generate `orderBy`, `with()`, or case-varied strings — which is exactly why 1.1–1.3 survived. Extend:

- `tests/Fuzz/QueryCorrectnessTest.php`: randomly append `->orderBy($col, $dir)` (from a pool of columns incl. strings and nullables) and randomly add `->with($relation)` to generated queries; assert result **order** (`assertSame` on plucked keys, not set equality — verify the existing assertions are order-sensitive and tighten if not).
- `tests/Fuzz/RelationalCorrectnessTest.php`: random `orderBy` on relation reads; case-varied pivot string attributes.
- Add random extra-predicate shapes (operator pool: `=`, `!=`, `<`, `>=`, `whereIn`, `whereNull`, `whereBetween`) over both warm and cold entries.

**Acceptance:** with the Phase 1 fixes reverted locally, the new fuzz dimensions fail; with fixes applied, `composer fuzz` is green across several seeds.

---

## Phase 2 — Hardening the drop-in claim

Order within this phase is by user exposure. All are test-first: write the differential test, observe (pass = document as covered / fail = fix).

### 2.1 Iteration/pagination differential tests

`chunk`, `chunkById`, `cursor`, `lazy`, `lazyById`, `each`, `paginate`, `simplePaginate`, `cursorPaginate` are not overridden. They *should* be safe (they route through `get()`/`getModels()` or use limits that trigger bail-outs) but nothing proves it. Add a feature test file running each against the oracle with a warm map, asserting identical results **and** documenting (via `explain()`) which ones elide and which bypass. Pay attention to `chunkById` + a mass `update()` inside the chunk callback — the classic mutation-during-iteration case interacting with coverage invalidation.

### 2.2 Cast coverage in predicate evaluation

Current models only exercise `boolean`/`integer`/`float` casts. Add test models/columns for `datetime`/`immutable_datetime`, `json`/`array`, backed `enum`, `decimal:2`, and `encrypted` casts, then test predicate evaluation and unique-key lookups against each. Expected behaviour: anything not provably equivalent to the DB comparison returns `Unknown` and falls through. Watch specifically:

- `where('created_at', $carbon)` vs stored string — Carbon instances in predicates vs raw column values.
- `whereIn` over enum-backed values.
- `encrypted` casts: ciphertext differs per row; equality elision must never fire on original (encrypted) values.

### 2.3 Morph map aliasing

`MemoryMorphTo` resolves via `Relation::getMorphedModel()` (good), but there are no tests with `Relation::enforceMorphMap()`/`morphMap()` active. Add tests: aliased morph reads served from memory, graph edges keyed correctly under aliases, `whereHas` on a morph relation under an alias.

### 2.4 Custom pivot models (`->using()`), pivot casts, `withTimestamps`

`MemoryBelongsToMany::pivotAttributesFromModel()` (`src/Relations/MemoryBelongsToMany.php:968`) reads attributes off whatever pivot accessor holds; a custom `Pivot` subclass with casts may store cast values that diverge from raw DB values used in `wherePivot` SQL. Add a custom pivot model test (with a boolean + datetime cast) and verify `wherePivot` elision matches the oracle, falling back if not.

### 2.5 Accessors, mutators, `$appends`

No test model defines an accessor today, yet `FactSource::AppendedAttribute` exists. Add a model with a classic accessor shadowing a real column and an appended computed attribute; assert facts record DB values (not accessor output) and predicates on the shadowed column still match SQL.

### 2.6 Table prefixes and per-model connections

- A connection configured with `'prefix' => 'app_'`: schema discovery, coverage keys, and qualified-column handling (`users.id` vs `app_users.id`) all need a differential test pass.
- `$model->setConnection()` switching mid-request: entries must not leak across connections (the store key includes connection name — prove it with a test).

### 2.7 Octane / worker-loop simulation + real queue jobs

- Simulate two request scopes in one process: boot, query, call the terminating flush, assert the second "request" gets zero memory hits and no stale instances (especially `ScopeFingerprinter` statics and `SchemaDiscovery` cache behaviour across flushes).
- Replace event-only queue tests with a real dispatched job via the `sync`/`database` queue driver asserting the map is flushed between jobs and that a job exception still flushes.

### 2.8 Store size caps

`IdentityGraph` has `max_edges`/`max_coverage_entries`; `IdentityMapStore::$entries`/`$absent`, `UniqueKeyIndex`, and `CoverageRegistry` are unbounded (`src/Store/IdentityMapStore.php:30-33`, `src/Store/UniqueKeyIndex.php:12-15`, `src/Coverage/CoverageRegistry.php:19`). A single queue job iterating millions of rows grows without bound (job-boundary flushes don't help within one job). Add config caps mirroring the graph's flush-on-overflow behaviour (flush-all is the safe semantics here; partial eviction would corrupt coverage/absence reasoning — do **not** LRU-evict individual entries while coverage references them). Default caps generous (e.g. 100k entries), env-overridable, documented.

### 2.9 Replace `debug_backtrace` relation-name guessing

`HasIdentityMap::newHasMany()`/`newMorphMany()` (`src/HasIdentityMap.php:75-86`) guess the relation name from the backtrace. Wrong/null for trait-hosted relations, `resolveRelationUsing()`, and unusual call depths — consequence is silent loss of optimization plus a backtrace cost on every relation instantiation. Replace with Laravel's own guessing (`Relation::$relationName` is not available, but `Model::getRelations()` resolution or the `guessBelongsToRelations`-style approach via `debug_backtrace` is what Eloquent itself uses for `belongsTo` — at minimum hoist the guess behind a static per-class memo and add tests for the trait-hosted and `resolveRelationUsing()` cases documenting the fallback behaviour).

### 2.10 Small confirmed nits

- `MemoryMorphTo` logs `PlanType::ReturnBelongsToFromMemory` (`src/Relations/MemoryMorphTo.php:103`) — add `ReturnMorphToFromMemory` and use it (observability only; cheap).
- Unique-key lookup returns `null` without evicting when an attribute fact is missing (around `src/Store/IdentityMapStore.php:525-529` — the mismatch branch evicts, the missing-fact branch doesn't). Make both evict so the next lookup goes to SQL once instead of missing forever.
- Document in the README that `initializeHasIdentityMap` caches the table name per class — models computing `getTable()` dynamically (per-tenant table switching) must not use the trait as-is.

---

## Phase 3 — "Extreme" features (after Phases 1–2 are green)

Ordered by value-to-risk ratio. Each must keep the oracle-differential property and add fuzz dimensions for itself.

### 3.1 In-memory `ORDER BY` (+ `LIMIT`) via driver semantics

Turn the Phase 1.1 bail-outs back into elisions: sort memory-served results using `ColumnSemantics` (collation-aware string compare, driver null-ordering via `NullOrdering`), then apply `limit`/`offset` in memory for coverage-served sets. Bail (`Unknown`) for column types semantics can't rank. This generalises `sortForFirst()` — fold it into the shared implementation. Fuzzers from 1.4 are the safety net.

### 3.2 `OR`-tree predicate support

Add an `OrNode` to the predicate tree (`src/Predicate/`), extend `PredicateExtractor` for `orWhere`/nested closures, `PredicateEvaluator` (three-valued logic: `Match`/`Reject`/`Unknown` propagation through OR), and — the hard part — `SubsetChecker` for OR regions (a query is covered if its region is a subset of a recorded region; OR widens regions, so recorded `A OR B` covers query `A`). This unlocks the largest class of real-world queries currently bypassed. Land it in stages: evaluator first (key-set pruning with OR predicates), coverage subset-checking second.

### 3.3 `hasOne` / `morphOne` / `hasManyThrough` memory relations

The obvious missing relation types. `hasOne`/`morphOne` are near-copies of the `belongsTo` pattern (single related row, FK on the other side); follow `MemoryHasMany`'s clean-load + completeness model. `hasManyThrough` can be served from the graph when both hops have complete coverage. Also consider `latestOfMany`/`oneOfMany` — these add aggregating subqueries, so the right first move is to verify they bail (add tests), then decide if memory evaluation is worth it.

### 3.4 Coverage union/composition

Two recorded regions whose union provably covers a new query (e.g. recorded `role = 'a'` and `role = 'b'` covering `whereIn('role', ['a','b'])`). Extend `SubsetChecker`; keep the conservative default of refusing when in doubt.

### 3.5 Debug toolbar / Telescope integration

The `QueryDecided` event and `Explanation::toArray()` already exist. Ship a small first-party Telescope watcher and/or Debugbar collector package (or an optional class behind `class_exists` guards) rendering the per-request decision stream: plans, SQL elided/executed counts, hit rate. This is the adoption feature — people will trust the package when they can watch it decide.

### 3.6 Opt-in cross-request layer (research spike first)

Serialize coverage **facts** and absence markers (never model instances) into APCu/Redis with explicit invalidation hooks. This is a different risk class (cross-process staleness); write a design doc before code, keep it firmly opt-in, and do not let it compromise the per-request guarantees. Park it if it threatens Phase 1/2 invariants.

---

## Phase 4 — Release hygiene (can interleave with Phase 3)

1. **composer.json**: real `description` (currently "A Laravel package."), `keywords`, `authors`, `support` links. Re-evaluate the five ignored audit advisories (`config.audit.ignore`) — document why each is ignored or drop the ignore.
2. **CHANGELOG.md**: adopt Keep a Changelog; backfill from git history at least to the `attribute_truth` → `mode` rename (already documented in README — link it).
3. **README "Known limitations" section**: out-of-process writes, `DB::` raw writes, FK `ON DELETE CASCADE`, triggers, dynamic `getTable()` models, `withoutEvents()` writes through *non-mapped* instances. Several are mentioned in passing; collect them in one honest table.
4. **BC policy**: state the pre-1.0 versioning policy (what counts as breaking, deprecation window), then tag a 0.x release once Phases 1–2 are complete. 1.0 criteria: Phase 1 + 2 done, limitations documented, two consecutive scheduled fuzz runs (Monday job) green.
5. **CI top-ups**: `composer audit` job; consider adding the `extreme-defaults` job permutation for `process_truth` mode (full suite under `IDENTITY_MAP_MODE=process_truth`) alongside the existing backfill permutation.

---

## Suggested working order (flat list)

1. 1.1 ORDER BY bail-outs + regression tests
2. 1.2 eager loads on `first()`/`sole()` + regression tests
3. 1.3 pivot driver semantics + regression tests
4. 1.4 fuzzer dimensions (orderBy / with / case-varied strings / predicate shapes)
5. 2.1 iteration/pagination differential tests
6. 2.2 cast coverage
7. 2.10 small nits (morph PlanType, unique-key eviction, README caveat)
8. 2.3 morph maps, 2.4 custom pivots, 2.5 accessors
9. 2.6 prefixes/connections, 2.7 Octane/queue, 2.8 store caps, 2.9 backtrace removal
10. 4.1–4.3 hygiene (cheap, do alongside)
11. 3.1 in-memory ORDER BY, 3.2 OR-trees, 3.3 new relations, 3.4 coverage union
12. 3.5 toolbar integration
13. 4.4 release tagging; 3.6 cross-request spike last
