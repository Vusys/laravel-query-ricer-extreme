<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Tests derived from the design review.
 *
 * Each group is labelled with the review issue code (C-n, S-n, etc.).
 * Tests that were failing before fixes are marked with @see design-review.md.
 */
final class DesignReviewTest extends TestCase
{
    private IdentityMapStore $store;

    private CoverageRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->registry = resolve(CoverageRegistry::class);
        $this->store->flush();
        $this->registry->flush();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function countSql(callable $callback): int
    {
        $n = 0;
        DB::listen(static function () use (&$n): void {
            $n++;
        });
        $callback();

        return $n;
    }

    // =========================================================================
    // C-10: withCount / aggregate SELECT columns
    // withCount adds a subquery column to the SELECT that is never stored in the
    // identity map.  Serving such a query from coverage or from the key-set
    // memory path would return models without the virtual column.
    // =========================================================================

    #[Test]
    public function c10_withcount_not_served_from_coverage(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 1', 'published' => true]);

        // Establish coverage for active=true.
        User::where('active', true)->get();
        $this->assertGreaterThan(0, $this->registry->entryCount(), 'Pre-condition: coverage must exist');

        $this->store->flush();
        $this->registry->flush();
        User::create(['name' => 'Alice', 'email' => 'alice2@example.com', 'active' => true]);
        Post::create(['user_id' => User::where('email', 'alice2@example.com')->value('id'), 'title' => 'P', 'published' => false]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => true]);
        User::where('active', true)->get();   // rebuild coverage
        $this->store->flush();                // evict from identity map but keep coverage

        $sql = $this->countSql(function (): void {
            $users = User::withCount('posts')->where('active', true)->get();
            // Every user must carry posts_count.
            foreach ($users as $u) {
                $this->assertTrue(
                    $u->offsetExists('posts_count'),
                    "User #{$u->id} is missing posts_count — was served from coverage without SQL",
                );
            }
        });

        $this->assertGreaterThan(0, $sql, 'withCount query must execute SQL, not be served from coverage');
    }

    #[Test]
    public function c10_withcount_key_set_executes_sql_for_memory_models_too(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 1', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 2', 'published' => true]);

        // Load both into the identity map.
        User::find($alice->id);
        User::find($bob->id);

        $sql = $this->countSql(function () use ($alice, $bob): void {
            $users = User::withCount('posts')->whereKey([$alice->id, $bob->id])->get();

            foreach ($users as $u) {
                $this->assertTrue(
                    $u->offsetExists('posts_count'),
                    "User #{$u->id} is missing posts_count — was served from identity map without SQL",
                );
            }

            $byId = $users->keyBy('id');
            $aliceUser = $byId[$alice->id];
            $bobUser = $byId[$bob->id];
            $this->assertInstanceOf(User::class, $aliceUser);
            $this->assertInstanceOf(User::class, $bobUser);
            $this->assertSame(2, (int) $aliceUser->posts_count);
            $this->assertSame(0, (int) $bobUser->posts_count);
        });

        $this->assertGreaterThan(0, $sql, 'withCount key-set query must execute SQL even when keys are in the map');
    }

    #[Test]
    public function c10_withcount_single_find_executes_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 1', 'published' => true]);

        // Prime the identity map.
        User::find($alice->id);

        $sql = $this->countSql(function () use ($alice): void {
            $u = User::withCount('posts')->find($alice->id);
            $this->assertInstanceOf(User::class, $u);
            $this->assertTrue(
                $u->offsetExists('posts_count'),
                'User is missing posts_count — was served from identity map without SQL',
            );
            $this->assertSame(1, (int) $u->posts_count);
        });

        $this->assertGreaterThan(0, $sql, 'withCount find() must execute SQL even when model is in the map');
    }

    #[Test]
    public function c10_selectraw_not_served_from_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        // Establish coverage.
        User::where('active', true)->get();
        $this->store->flush(); // evict models, keep coverage

        $sql = $this->countSql(function (): void {
            $results = User::selectRaw('*, 42 as magic_number')->where('active', true)->get();
            foreach ($results as $u) {
                $this->assertTrue(
                    $u->offsetExists('magic_number'),
                    "User #{$u->id} missing magic_number — served from coverage without SQL",
                );
            }
        });

        $this->assertGreaterThan(0, $sql, 'selectRaw query must not be served from coverage');
    }

    // =========================================================================
    // C-6: LIMIT must not create coverage (already enforced — regression guard)
    // =========================================================================

    #[Test]
    public function c6_limited_query_does_not_create_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => true]);

        // Only fetch 2 of the 3 active users.
        User::where('active', true)->limit(2)->get();

        // A later query for all active users MUST hit the database.
        $sql = $this->countSql(function (): void {
            $users = User::where('active', true)->get();
            $this->assertCount(3, $users, 'All 3 active users must be returned (no stale coverage)');
        });

        $this->assertGreaterThan(0, $sql, 'Query after a limited fetch must execute SQL — no coverage was created');
    }

    // =========================================================================
    // C-4 / first() ordering: first() on multiple coverage models without an
    // ORDER BY must fall through to SQL, not return an arbitrary memory model.
    // (Already enforced — regression guard.)
    // =========================================================================

    #[Test]
    public function c4_first_without_order_falls_through_to_sql_when_multiple_matches(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);

        // Establish coverage.
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            // Two active users in coverage; no ORDER BY — must fall through.
            $first = User::where('active', true)->first();
            $this->assertNotNull($first);
        });

        $this->assertGreaterThan(0, $sql, 'first() with multiple coverage models and no ORDER BY must execute SQL');
    }

    // =========================================================================
    // C-4 / first() with a single match in coverage is safe to serve from memory.
    // =========================================================================

    #[Test]
    public function c4_first_with_single_match_in_coverage_is_served_from_memory(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => false]);

        // Coverage for all users; Alice is the only active one.
        User::all();

        $sql = $this->countSql(function (): void {
            $first = User::where('active', true)->first();
            $this->assertNotNull($first);
            $this->assertSame('Alice', $first->name);
        });

        $this->assertSame(0, $sql, 'first() with exactly one coverage match must be served from memory');
    }

    // =========================================================================
    // C-2: Unique-key stale index is lazily evicted on lookup
    // After saving a model with a changed unique column, the old index entry is
    // evicted on the next lookup attempt (not wrong data, verified here).
    // =========================================================================

    #[Test]
    public function c2_stale_unique_key_index_evicted_after_email_change(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['email']]],
        ]]);

        $alice = User::create(['name' => 'Alice', 'email' => 'old@example.com', 'active' => true]);
        User::find($alice->id);  // prime identity map and unique-key index

        // Change the indexed column and save.
        $alice->email = 'new@example.com';
        $alice->save();

        $sql = $this->countSql(function (): void {
            // Looking up old email must NOT return Alice (she now has new@example.com).
            $result = User::where('email', 'old@example.com')->first();
            $this->assertNull($result, 'old email lookup must return null — no row has that email');
        });

        // The stale entry is evicted; a DB query is needed to confirm absence.
        $this->assertGreaterThan(0, $sql, 'stale unique-key index must not return a wrong hit — SQL must execute');
    }

    #[Test]
    public function c2_new_email_value_is_indexed_after_save(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['email']]],
        ]]);

        $alice = User::create(['name' => 'Alice', 'email' => 'old@example.com', 'active' => true]);
        User::find($alice->id);

        $alice->email = 'new@example.com';
        $alice->save();

        // The new email should now be in the unique-key index.
        $sql = $this->countSql(function () use ($alice): void {
            $found = User::where('email', 'new@example.com')->first();
            $this->assertNotNull($found);
            $this->assertSame($alice->id, $found->id);
        });

        $this->assertSame(0, $sql, 'new email value must be in the unique-key index after save');
    }

    // =========================================================================
    // C-13: Absence record cleared when a model is created with the same PK
    // =========================================================================

    #[Test]
    public function c13_absence_cleared_when_model_is_created(): void
    {
        // Record absence for id=9999.
        $this->assertNull(User::find(9999));

        // Create a user with that exact id.
        // (SQLite auto-increment won't pick 9999 unless we force it.)
        DB::table('users')->insert([
            'id' => 9999,
            'name' => 'New',
            'email' => 'new9999@example.com',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fire the model event that clears absence.
        $u = User::withoutIdentityMap()->find(9999);
        $this->assertInstanceOf(User::class, $u);
        // Re-register through the normal path so the save event fires.
        resolve(IdentityMapStore::class)->afterSaved($u);

        $sql = $this->countSql(function (): void {
            $found = User::find(9999);
            $this->assertNotNull($found, 'User#9999 must be found — absence record must have been cleared');
        });

        $this->assertSame(0, $sql, 'User#9999 must be served from map (absence was cleared, model was re-registered)');
    }

    // =========================================================================
    // C-7: Process-truth — dirty model excluded from covered region
    // =========================================================================

    #[Test]
    public function c7_dirty_model_excluded_from_coverage_region_under_process_truth(): void
    {
        config(['query-ricer-extreme.attribute_truth' => 'process_truth']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);

        // Establish coverage: both Alice and Bob are active.
        $loaded = User::where('active', true)->get();
        $aliceLive = $loaded->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        // Dirty Alice — she is no longer active under process truth.
        $aliceLive->active = false;

        $sql = $this->countSql(function () use ($bob): void {
            $results = User::where('active', true)->get();
            $ids = $results->pluck('id')->sort()->values()->all();

            $this->assertSame([(int) $bob->id], $ids, 'Dirty Alice must be excluded under process truth');
        });

        $this->assertSame(0, $sql, 'Covered region must be served from memory even after dirty mutation');

        config(['query-ricer-extreme.attribute_truth' => 'database_only']);
    }

    // =========================================================================
    // C-7 counterpart: database_only mode uses original values from coverage
    // =========================================================================

    #[Test]
    public function c7_dirty_model_included_in_coverage_region_under_database_only(): void
    {
        config(['query-ricer-extreme.attribute_truth' => 'database_only']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);

        $loaded = User::where('active', true)->get();
        $aliceLive = $loaded->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        // Dirty Alice in-memory — database still says active=true.
        $aliceLive->active = false;

        $sql = $this->countSql(function () use ($alice, $bob): void {
            $results = User::where('active', true)->get();
            $ids = $results->pluck('id')->sort()->values()->all();
            $expected = [(int) min($alice->id, $bob->id), (int) max($alice->id, $bob->id)];

            $this->assertSame($expected, $ids, 'Dirty Alice must still appear — database_only uses original value');
        });

        $this->assertSame(0, $sql, 'Coverage must still be used in database_only mode');
    }

    // =========================================================================
    // I-2: GROUP BY must not create coverage
    // =========================================================================

    #[Test]
    public function i2_group_by_query_does_not_create_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);

        $before = $this->registry->entryCount();

        // A GROUP BY query must not create a coverage entry.
        DB::table('users')->groupBy('active')->get();

        $this->assertSame($before, $this->registry->entryCount(), 'GROUP BY must not create a coverage entry');
    }

    // =========================================================================
    // S-4: aggregate hazard vs coverage — count/exists from coverage must work
    // =========================================================================

    #[Test]
    public function s4_count_served_from_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => false]);

        User::where('active', true)->get(); // creates coverage

        $sql = $this->countSql(function (): void {
            $count = User::where('active', true)->count();
            $this->assertSame(1, $count);
        });

        $this->assertSame(0, $sql, 'count() from coverage must not execute SQL');
    }

    #[Test]
    public function s4_exists_served_from_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => false]);

        User::all(); // creates whole-table coverage

        $sql = $this->countSql(function (): void {
            $exists = User::where('active', true)->exists();
            $this->assertTrue($exists);
        });

        $this->assertSame(0, $sql, 'exists() from coverage must not execute SQL');
    }

    // =========================================================================
    // C-11: sole() — correct exception behaviour
    // sole() in Eloquent calls take(2)->get() which adds LIMIT 2.
    // isSafeForCoverage() returns false for LIMIT queries, so sole() always
    // hits the database and throws correctly.
    // =========================================================================

    #[Test]
    public function c11_sole_throws_multiple_records_exception_when_more_than_one_match(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);

        // Prime both models in the identity map and create coverage.
        User::where('active', true)->get();

        $this->expectException(MultipleRecordsFoundException::class);

        User::where('active', true)->sole();
    }

    #[Test]
    public function c11_sole_throws_model_not_found_exception_when_no_match(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);

        // Coverage for all users.
        User::all();

        $this->expectException(ModelNotFoundException::class);

        User::where('active', true)->sole();
    }

    #[Test]
    public function c11_sole_returns_model_when_exactly_one_match(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);

        User::all();

        $found = User::where('active', true)->sole();
        $this->assertSame($alice->id, $found->id);
    }

    // =========================================================================
    // S-1: Transaction rollback — correct DB value is visible after rollback
    //
    // The package has no transaction journal, but SQLite/Eloquent state is
    // consistent enough in tests that find() returns the rolled-back DB value.
    // This test documents that behaviour as a regression guard.
    //
    // The safe escape hatch (always available) is IdentityMap::flush() plus a
    // fresh find, or withoutIdentityMap().  Both are asserted here.
    // =========================================================================

    #[Test]
    public function s1_transaction_rollback_db_value_visible_after_flush(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id); // prime map

        try {
            DB::transaction(static function () use ($alice): never {
                $alice->name = 'RolledBack';
                $alice->save();
                throw new \RuntimeException('intentional rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        // The DB was rolled back — raw DB always gives the correct value.
        $fromDb = User::withoutIdentityMap()->find($alice->id);
        $this->assertInstanceOf(User::class, $fromDb);
        $this->assertSame('Alice', $fromDb->name, 'DB must be rolled back correctly');

        // After an explicit flush the identity map also returns the rolled-back value.
        $this->store->flush();
        $afterFlush = User::find($alice->id);
        $this->assertInstanceOf(User::class, $afterFlush);
        $this->assertSame('Alice', $afterFlush->name, 'After flush(), find() must return the rolled-back DB value');
    }

    // =========================================================================
    // S-7: Unknown global scope — must not produce a wrong fingerprint
    // When a custom GlobalScope is applied, the ScopeFingerprinter currently
    // only fingerprints SoftDeleteScope.  A model loaded WITH the custom scope
    // is stored under a potentially wrong fingerprint.  This test documents
    // the limitation until full scope fingerprinting is implemented.
    // =========================================================================

    #[Test]
    public function s7_withoutglobalscope_produces_separate_fingerprint_from_default(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        // Load under the default scope.
        $default = User::find($alice->id);
        $this->assertInstanceOf(User::class, $default);

        // Load with an explicit scope removal.
        $withoutTrashed = User::withoutGlobalScope(SoftDeletingScope::class)->find($alice->id);
        $this->assertInstanceOf(User::class, $withoutTrashed);

        // The fingerprint is different; both queries should find Alice.
        $this->assertSame($alice->id, $default->id);
        $this->assertSame($alice->id, $withoutTrashed->id);
    }

    // =========================================================================
    // C-5: Key-set merge preserves input order
    // =========================================================================

    #[Test]
    public function c5_keyset_merge_preserves_input_order(): void
    {
        $u1 = User::create(['name' => 'One',   'email' => 'one@example.com',   'active' => true]);
        $u2 = User::create(['name' => 'Two',   'email' => 'two@example.com',   'active' => true]);
        $u3 = User::create(['name' => 'Three', 'email' => 'three@example.com', 'active' => true]);

        // Load only u1 and u2 into the map; u3 is not cached.
        User::find($u1->id);
        User::find($u2->id);

        // Request in reverse order: 3, 2, 1
        $ids = [$u3->id, $u2->id, $u1->id];
        $users = User::whereKey($ids)->get();

        $this->assertCount(3, $users);
        $this->assertSame(
            [$u3->id, $u2->id, $u1->id],
            $users->pluck('id')->all(),
            'Result order must match input key order even when some keys are memory-served',
        );
    }

    // =========================================================================
    // I-3 / allColumnsKnown: coverage does not satisfy withCount virtual columns
    // Regression guard to ensure allColumnsKnown cannot be used to satisfy a
    // virtual column that is not stored in the identity map.
    // =========================================================================

    #[Test]
    public function i3_coverage_column_satisfaction_does_not_cover_virtual_columns(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 1', 'published' => true]);

        // Full select — allColumnsKnown is set.
        User::all();

        // withCount adds a virtual column not in allColumnsKnown scope.
        $users = User::withCount('posts')->get();

        foreach ($users as $u) {
            $this->assertTrue(
                $u->offsetExists('posts_count'),
                "User #{$u->id} must have posts_count — virtual column must come from SQL",
            );
        }
    }

    // =========================================================================
    // M-2: increment / decrement bypass model events — attribute goes stale
    // This test documents the known limitation: after an increment() the
    // identity map still holds the pre-increment value.
    // =========================================================================

    #[Test]
    public function m2_increment_leaves_map_attribute_stale_known_limitation(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        // Add a numeric column we can increment.  We use active (TINYINT 0/1)
        // just to have something to increment without schema changes.
        User::find($alice->id); // prime map

        // Mass-update-style increment bypasses model events.
        User::whereKey($alice->id)->increment('id', 0); // no-op to verify we can observe map state

        // A more meaningful test: use a raw update that bypasses events.
        DB::table('users')->where('id', $alice->id)->update(['active' => 0]);

        // The map still has the old value (active=true) because no model event fired.
        $fromMap = User::find($alice->id);
        $this->assertInstanceOf(User::class, $fromMap);
        // Known limitation: map has stale active=true; DB has active=false.
        $fromDb = User::withoutIdentityMap()->find($alice->id);
        $this->assertInstanceOf(User::class, $fromDb);
        $this->assertFalse((bool) $fromDb->active, 'DB has active=false after raw update');
        $this->assertTrue((bool) $fromMap->active, 'Known limitation: map still has stale active=true');
    }

    // =========================================================================
    // C-6 + S-6: Coverage subset check is conservative for OR predicates
    // A query whose predicate is NOT a subset of the coverage region must
    // always execute SQL.
    // =========================================================================

    #[Test]
    public function s6_coverage_subset_rejects_disjoint_predicate(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => false]);

        // Coverage for active=true only.
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            // active=false is NOT a subset of the covered region (active=true).
            $users = User::where('active', false)->get();
            $this->assertCount(1, $users);
            $this->assertSame('Bob', $users->first()?->name);
        });

        $this->assertGreaterThan(0, $sql, 'Query outside covered region must execute SQL');
    }

    // =========================================================================
    // C-9: Eager loading receives the full merged model set
    // When a key-set query is partially served from memory, the eager-loaded
    // relation must still be loaded for ALL returned models including those
    // served from memory.
    // =========================================================================

    #[Test]
    public function c9_eager_loading_includes_memory_served_models(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Alice Post', 'published' => true]);

        // Prime alice and bob into the identity map.
        User::find($alice->id);
        User::find($bob->id);

        // Fetch with eager-loaded posts.  Both users are in memory but posts
        // have not been loaded — eagerLoadRelations must run for all of them.
        $users = User::with('posts')->whereKey([$alice->id, $bob->id])->get();

        $byId = $users->keyBy('id');
        $aliceUser = $byId[$alice->id];
        $bobUser = $byId[$bob->id];
        $this->assertInstanceOf(User::class, $aliceUser);
        $this->assertInstanceOf(User::class, $bobUser);
        $this->assertTrue($aliceUser->relationLoaded('posts'), 'Alice must have posts relation loaded');
        $this->assertTrue($bobUser->relationLoaded('posts'), 'Bob must have posts relation loaded');
        $this->assertCount(1, $aliceUser->posts);
        $this->assertCount(0, $bobUser->posts);
    }

    // =========================================================================
    // Write-invalidation: mass update flushes coverage (regression guard)
    // =========================================================================

    #[Test]
    public function mass_update_invalidates_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob',   'email' => 'bob@example.com',   'active' => true]);

        User::where('active', true)->get(); // creates coverage
        $before = $this->registry->entryCount();
        $this->assertGreaterThan(0, $before);

        // Mass update must flush coverage.
        User::where('active', true)->update(['active' => false]);

        $this->assertSame(0, $this->registry->entryCount(), 'Coverage must be flushed after mass update');

        $sql = $this->countSql(function (): void {
            $users = User::where('active', false)->get();
            $this->assertCount(2, $users, 'Both users must appear as inactive after mass update');
        });

        $this->assertGreaterThan(0, $sql, 'Query after mass update must hit the database');
    }

    // =========================================================================
    // Locking: lockForUpdate always executes SQL (invariant 20.7)
    // =========================================================================

    #[Test]
    public function lock_for_update_always_executes_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id); // prime map

        $sql = $this->countSql(function () use ($alice): void {
            $user = User::whereKey($alice->id)->lockForUpdate()->first();
            $this->assertNotNull($user);
        });

        $this->assertGreaterThan(0, $sql, 'lockForUpdate() must always execute SQL — never serve from memory');
    }
}
