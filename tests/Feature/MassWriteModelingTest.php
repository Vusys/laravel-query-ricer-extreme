<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Milestone 8: aggressive write modeling.
 *
 * Covers:
 * - Mass-update predicate evaluation for mapped models
 * - Mass-delete predicate evaluation for mapped models
 * - Selective coverage invalidation on mass write and single-model save
 */
final class MassWriteModelingTest extends TestCase
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

    private function countSql(callable $callback): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $callback();
            $count = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return $count;
    }

    // =========================================================================
    // Mass-update: identity map predicate evaluation
    // =========================================================================

    #[Test]
    public function mass_update_match_applies_values_to_identity_store_entry(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        User::where('active', false)->update(['active' => true]);

        // Entry updated in-place; subsequent find must serve updated value from memory.
        $sql = $this->countSql(function () use ($alice): void {
            $found = User::find($alice->id);
            $this->assertInstanceOf(User::class, $found);
            $this->assertSame(true, (bool) $found->active, 'active should be updated to true in identity map');
        });

        $this->assertSame(0, $sql, 'Updated entry must be served from memory without SQL');
    }

    #[Test]
    public function mass_update_reject_leaves_entry_untouched(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id); // warm entry

        User::where('active', false)->update(['active' => true]); // Alice does NOT match

        $sql = $this->countSql(function () use ($alice): void {
            $found = User::find($alice->id);
            $this->assertInstanceOf(User::class, $found);
            $this->assertSame(true, (bool) $found->active, 'active should remain true');
        });

        $this->assertSame(0, $sql, 'Rejected entry must stay in memory and serve without SQL');
    }

    #[Test]
    public function mass_update_unknown_evicts_entry_and_falls_through_to_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);

        // Flush the store after create so the entry has not yet stored 'active'.
        $this->store->flush();

        // First and only load is partial — 'active' is not in the attribute facts.
        User::select('id', 'name')->get();

        $this->assertSame(1, $this->store->debugStats()['entries']);

        // Predicate references 'active', which is not in the partial facts → Unknown → evict.
        User::where('active', false)->update(['active' => true]);

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Unknown entry must be evicted');

        $sql = $this->countSql(function () use ($alice): void {
            User::find($alice->id);
        });

        $this->assertGreaterThan(0, $sql, 'After eviction, find must hit SQL');
    }

    #[Test]
    public function mass_update_with_complex_query_flushes_identity_store(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        $this->assertSame(1, $this->store->debugStats()['entries']);

        // A join prevents predicate extraction — must fall back to full store flush.
        User::join('users as u2', 'users.id', '=', 'u2.id')
            ->where('users.active', false)
            ->update(['users.active' => true]);

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Full flush on unresolvable predicate');
    }

    #[Test]
    public function mass_update_with_multiple_values_updates_all_columns(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        User::where('active', false)->update(['active' => true, 'name' => 'Updated']);

        $sql = $this->countSql(function () use ($alice): void {
            $found = User::find($alice->id);
            $this->assertInstanceOf(User::class, $found);
            $this->assertSame(true, (bool) $found->active);
            $this->assertSame('Updated', $found->name);
        });

        $this->assertSame(0, $sql, 'All updated columns reflected in memory');
    }

    // =========================================================================
    // Mass-delete (soft): identity map predicate evaluation
    // =========================================================================

    #[Test]
    public function mass_soft_delete_match_records_absence_and_returns_null_without_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        User::where('active', false)->delete(); // soft-delete matching

        $sql = $this->countSql(function () use ($alice): void {
            $result = User::find($alice->id);
            $this->assertNull($result, 'Soft-deleted match must not be returned');
        });

        $this->assertSame(0, $sql, 'Soft-deleted entry must return null from absence tracking');
    }

    #[Test]
    public function mass_soft_delete_reject_leaves_entry_alive(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::find($alice->id); // warm entry

        User::where('active', false)->delete(); // Alice does NOT match

        $sql = $this->countSql(function () use ($alice): void {
            $found = User::find($alice->id);
            $this->assertNotNull($found, 'Non-matching entry must survive mass delete');
        });

        $this->assertSame(0, $sql, 'Surviving entry must be served from memory without SQL');
    }

    #[Test]
    public function mass_soft_delete_unknown_evicts_entry(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);

        // Flush the store after create so the entry has not yet stored 'active'.
        $this->store->flush();

        // First and only load is partial — 'active' is not in the attribute facts.
        User::select('id', 'name')->get();

        $this->assertSame(1, $this->store->debugStats()['entries']);

        // Predicate references 'active', which is not in the partial facts → Unknown → evict.
        User::where('active', false)->delete();

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Unknown entry must be evicted on mass delete');
    }

    // =========================================================================
    // Mass-delete (hard, no SoftDeletes): uses Post model
    // =========================================================================

    #[Test]
    public function mass_hard_delete_match_marks_entry_deleted(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $post = Post::create(['user_id' => $alice->id, 'title' => 'Draft', 'published' => false]);
        Post::find($post->id); // warm entry

        Post::where('published', false)->delete();

        // Entry is Deleted (not absent) so find falls through to SQL and returns null.
        $sql = $this->countSql(function () use ($post): void {
            $result = Post::find($post->id);
            $this->assertNull($result);
        });

        $this->assertGreaterThan(0, $sql, 'Hard-deleted entry must fall through to SQL');
    }

    #[Test]
    public function mass_hard_delete_reject_leaves_entry_in_memory(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $post = Post::create(['user_id' => $alice->id, 'title' => 'Published', 'published' => true]);
        Post::find($post->id); // warm entry

        Post::where('published', false)->delete(); // post does NOT match

        $sql = $this->countSql(function () use ($post): void {
            $found = Post::find($post->id);
            $this->assertNotNull($found);
        });

        $this->assertSame(0, $sql, 'Non-matching hard-delete entry must stay in memory');
    }

    // =========================================================================
    // forceDelete: flushes identity store
    // =========================================================================

    #[Test]
    public function force_delete_flushes_identity_store_entries(): void
    {
        $graph = resolve(IdentityGraph::class);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'P1', 'published' => true]);
        User::find($alice->id);
        User::find($bob->id);
        User::where('active', true)->get();
        $alice->load('posts');

        $entriesBefore = $this->store->debugStats()['entries'];
        $this->assertGreaterThanOrEqual(2, $entriesBefore);
        $this->assertGreaterThan(0, $this->registry->entryCount(),
            'coverage must be warmed before forceDelete to make the post-flush assertion meaningful');
        $this->assertGreaterThan(0, $graph->edgeCount(),
            'graph must have at least one edge before forceDelete to make the post-flush assertion meaningful');

        User::withTrashed()->where('active', false)->forceDelete();

        $this->assertLessThan($entriesBefore, $this->store->debugStats()['entries'],
            'forceDelete must flush User entries from the identity store');
        $this->assertSame(0, $this->registry->entryCount(),
            'forceDelete must flush coverage entries for the User class');
        $this->assertSame(0, $graph->edgeCount(),
            'forceDelete must invalidate graph edges that target the User class');
    }

    // =========================================================================
    // Selective coverage: single-model save
    // =========================================================================

    #[Test]
    public function save_preserves_coverage_for_unrelated_column(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::where('active', true)->get(); // coverage: region active = true

        $this->assertSame(1, $this->registry->entryCount());

        $alice = User::where('name', 'Alice')->first();
        $this->assertNotNull($alice);
        $alice->name = 'Alicia';
        $alice->save(); // changes 'name', not 'active'

        $this->assertSame(1, $this->registry->entryCount(),
            'Coverage for active = true must survive a save that only changes name');
    }

    #[Test]
    public function save_flushes_coverage_for_referenced_column(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::where('name', 'Alice')->get(); // coverage: region name = 'Alice'

        $this->assertSame(1, $this->registry->entryCount());

        $alice = User::where('name', 'Alice')->first();
        $this->assertNotNull($alice);
        $alice->name = 'Alicia';
        $alice->save(); // changes 'name'

        $this->assertSame(0, $this->registry->entryCount(),
            'Coverage for name = Alice must be flushed after name is changed');
    }

    #[Test]
    public function noop_save_preserves_all_coverage(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::all(); // coverage: AND([])

        $this->assertSame(1, $this->registry->entryCount());

        // Save with no dirty attributes.
        $fetched = User::findOrFail($alice->id);
        $fetched->save();

        $this->assertSame(1, $this->registry->entryCount(),
            'No-op save must not flush any coverage');
    }

    #[Test]
    public function insert_flushes_all_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::all(); // coverage: AND([])

        $this->assertSame(1, $this->registry->entryCount());

        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);

        $this->assertSame(0, $this->registry->entryCount(),
            'Insert must flush all coverage since existing PK sets are now incomplete');
    }

    // =========================================================================
    // Selective coverage: mass update
    // =========================================================================

    #[Test]
    public function mass_update_preserves_coverage_for_unrelated_region(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::where('name', 'Alice')->get(); // coverage: region name = 'Alice'

        $this->assertSame(1, $this->registry->entryCount());

        User::where('active', false)->update(['active' => true]); // changes 'active', not 'name'

        $this->assertSame(1, $this->registry->entryCount(),
            'Coverage for name = Alice must survive a mass update on active');
    }

    #[Test]
    public function mass_update_flushes_coverage_for_region_referencing_updated_column(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);

        User::where('active', false)->get(); // coverage: region active = false
        User::where('name', 'Alice')->get(); // coverage: region name = 'Alice'

        $this->assertSame(2, $this->registry->entryCount());

        User::where('active', false)->update(['active' => true]); // changes 'active'

        $this->assertSame(1, $this->registry->entryCount(),
            'Only coverage referencing active should be flushed');
    }

    #[Test]
    public function mass_update_correctly_serves_updated_values_from_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);

        User::all(); // coverage: AND([]) with PKs [alice, bob]

        User::where('active', false)->update(['active' => true]); // Alice → active=true

        // Coverage AND([]) preserved; Alice's entry updated; query on active=true returns both.
        $sql = $this->countSql(function (): void {
            $users = User::where('active', true)->get();
            $this->assertCount(2, $users, 'Both users should now match active = true');
        });

        $this->assertSame(0, $sql, 'Results must be served from updated memory without SQL');
    }

    // =========================================================================
    // Mass-delete still flushes coverage
    // =========================================================================

    #[Test]
    public function mass_delete_flushes_all_coverage_for_model_class(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::all(); // coverage: AND([])

        $this->assertSame(1, $this->registry->entryCount());

        User::where('active', false)->delete();

        $this->assertSame(0, $this->registry->entryCount(),
            'Mass delete must flush all coverage for the model class');
    }

    // =========================================================================
    // Fallback paths: disabled store and unparseable predicates
    // =========================================================================

    #[Test]
    public function mass_update_with_disabled_store_falls_back_to_full_flush(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry
        User::all(); // coverage: AND([])

        $this->assertSame(1, $this->store->debugStats()['entries']);
        $this->assertSame(1, $this->registry->entryCount());

        $this->store->disabled(function (): void {
            User::where('active', false)->update(['active' => true]);
        });

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Disabled store triggers full flush of identity store');
        $this->assertSame(0, $this->registry->entryCount(), 'Disabled store triggers full flush of coverage registry');
    }

    #[Test]
    public function mass_update_with_or_predicate_falls_back_to_full_flush(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry
        User::where('name', 'Alice')->get(); // coverage: name = 'Alice'

        $this->assertSame(1, $this->store->debugStats()['entries']);
        $this->assertSame(1, $this->registry->entryCount());

        // orWhere makes the predicate un-parseable → fallback to full flush
        User::where('active', false)->orWhere('name', 'Alice')->update(['active' => true]);

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Unparseable predicate triggers full flush');
        $this->assertSame(0, $this->registry->entryCount(), 'Unparseable predicate triggers full coverage flush');
    }

    #[Test]
    public function mass_delete_with_disabled_store_falls_back_to_full_flush(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry
        User::where('active', false)->get(); // warm coverage

        $this->assertSame(1, $this->store->debugStats()['entries']);
        $this->assertSame(1, $this->registry->entryCount());

        $this->store->disabled(function (): void {
            User::where('active', false)->delete();
        });

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Disabled store triggers full flush on mass delete');
        $this->assertSame(0, $this->registry->entryCount(), 'Disabled store triggers full coverage flush on mass delete');
    }

    #[Test]
    public function mass_delete_with_or_predicate_falls_back_to_full_flush(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry
        User::where('active', false)->get(); // warm coverage

        $this->assertSame(1, $this->store->debugStats()['entries']);
        $this->assertSame(1, $this->registry->entryCount());

        // orWhere makes the predicate un-parseable → fallback to full flush
        User::where('active', false)->orWhere('name', 'Alice')->delete();

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Unparseable predicate triggers full flush on delete');
        $this->assertSame(0, $this->registry->entryCount(), 'Unparseable predicate triggers full coverage flush on delete');
    }

    // =========================================================================
    // applyMassUpdate: entries for other model classes are left intact
    // =========================================================================

    #[Test]
    public function mass_update_does_not_affect_entries_for_other_model_classes(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $post = Post::create(['user_id' => $alice->id, 'title' => 'Draft', 'published' => false]);
        User::find($alice->id); // warm User entry
        Post::find($post->id);  // warm Post entry

        $this->assertSame(2, $this->store->debugStats()['entries']);

        User::where('active', true)->update(['active' => false]);

        $this->assertSame(2, $this->store->debugStats()['entries'], 'Post entry must survive a User mass update');

        $sql = $this->countSql(function () use ($post): void {
            $found = Post::find($post->id);
            $this->assertNotNull($found, 'Post entry still served from memory');
        });

        $this->assertSame(0, $sql, 'Post served from memory — not evicted by User mass update');
    }

    // =========================================================================
    // applyMassDelete: Unknown entries are evicted; hard-delete model avoids soft-delete routing
    // =========================================================================

    #[Test]
    public function mass_hard_delete_unknown_evicts_partial_entry(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $post = Post::create(['user_id' => $alice->id, 'title' => 'Draft', 'published' => false]);

        // Flush after create so the entry has not yet stored 'published'.
        $this->store->flush();

        // Partial load — 'published' is not in the attribute facts.
        Post::select('id', 'title')->get();

        $this->assertSame(1, $this->store->debugStats()['entries']);

        // Hard delete: predicate references 'published', which is absent from partial facts → Unknown → evict.
        Post::where('published', false)->delete();

        $this->assertSame(0, $this->store->debugStats()['entries'], 'Unknown entry must be evicted by hard mass delete');

        $sql = $this->countSql(function () use ($post): void {
            Post::find($post->id);
        });

        $this->assertGreaterThan(0, $sql, 'After eviction, find must hit SQL');
    }

    // =========================================================================
    // applyMassDelete: entries not in Exists state are left alone
    // =========================================================================

    #[Test]
    public function mass_delete_skips_already_soft_deleted_entry(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry (state = Exists)

        // First delete: Alice matches, state → SoftDeleted, entry remains in store.
        User::where('active', false)->delete();

        $this->assertSame(1, $this->store->debugStats()['entries'], 'SoftDeleted entry still in store after first delete');

        // Entry is already SoftDeleted (not Exists) — the second delete has no effect.
        User::where('active', false)->delete();

        // Entry still present (skipped, not evicted) and still absent from default scope.
        $this->assertSame(1, $this->store->debugStats()['entries'], 'SoftDeleted entry survives second delete (skipped by line 588)');
        $this->assertNull(User::find($alice->id), 'Soft-deleted user still returns null from absence tracking');
    }

    // =========================================================================
    // applyMassUpdate / applyMassDelete: disabled store returns early without modification
    // =========================================================================

    #[Test]
    public function apply_mass_update_is_no_op_when_store_is_disabled(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        $this->assertSame(1, $this->store->debugStats()['entries']);

        // Call applyMassUpdate directly while the store is disabled — must return early.
        $this->store->disabled(function (): void {
            $this->store->applyMassUpdate(
                User::class,
                new ComparisonNode('active', '=', false),
                ['active' => true],
                new PredicateEvaluator,
            );
        });

        // Entry must be unchanged (applyMassUpdate returned early).
        $this->assertSame(1, $this->store->debugStats()['entries']);
        $found = User::find($alice->id);
        $this->assertInstanceOf(User::class, $found);
        $this->assertFalse((bool) $found->active, 'active must remain false — applyMassUpdate was a no-op');
    }

    #[Test]
    public function apply_mass_delete_is_no_op_when_store_is_disabled(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        $this->assertSame(1, $this->store->debugStats()['entries']);

        // Call applyMassDelete directly while the store is disabled — must return early.
        $this->store->disabled(function (): void {
            $this->store->applyMassDelete(
                User::class,
                new AndNode([]),
                new PredicateEvaluator,
                true,
            );
        });

        // Entry must be unchanged (applyMassDelete returned early).
        $this->assertSame(1, $this->store->debugStats()['entries']);
        $this->assertInstanceOf(User::class, User::find($alice->id));
    }

    // =========================================================================
    // HasIdentityMap::saved — selective coverage flush for single-model save
    // =========================================================================

    #[Test]
    public function save_flushes_coverage_selectively_via_has_identity_map_saved_callback(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::where('name', 'Alice')->get(); // coverage: name = 'Alice'
        User::where('active', true)->get();  // coverage: active = true
        $this->assertSame(2, $this->registry->entryCount());

        // Return the model stored in the coverage/identity-map entry.
        $alice = User::where('name', 'Alice')->first();
        $this->assertNotNull($alice);

        // Flush the identity store so applyMassUpdate (called during save's performUpdate)
        // finds no matching entry and does NOT call setRawAttributes(sync=true) on $alice.
        // That keeps $alice dirty after the SQL runs, so getChanges() returns a non-empty array
        // in the saved callback, triggering flushByColumns for the changed columns.
        $this->store->flush();

        $alice->name = 'Alicia';
        $alice->save();

        // name='Alice' entry flushed by HasIdentityMap::saved line 75; active=true preserved.
        $this->assertSame(1, $this->registry->entryCount(),
            'Only the coverage entry referencing name must be flushed');
    }

    // =========================================================================
    // Non-scalar update values (DB::raw, etc.) must fall through to a safe flush
    // rather than caching the Expression as the new column value.
    // =========================================================================

    #[Test]
    public function mass_update_with_db_raw_expression_evicts_entries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id); // warm entry

        $this->assertSame(1, $this->store->debugStats()['entries']);

        User::where('score', 10)->update(['score' => DB::raw('score + 1')]);

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(11, (int) $aliceAfter->score, 'cache must not retain a DB::raw Expression as the column value');
    }

    #[Test]
    public function mass_increment_invalidates_cached_entries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id); // warm entry

        User::where('id', $alice->id)->increment('score');

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(11, (int) $aliceAfter->score, 'cache must reflect the incremented value');
    }

    #[Test]
    public function mass_decrement_invalidates_cached_entries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id);

        User::where('id', $alice->id)->decrement('score', 3);

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(7, (int) $aliceAfter->score);
    }

    #[Test]
    public function mass_increment_each_invalidates_cached_entries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id);

        User::where('id', $alice->id)->incrementEach(['score' => 5]);

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(15, (int) $aliceAfter->score);
    }

    #[Test]
    public function mass_decrement_each_invalidates_cached_entries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id);

        User::where('id', $alice->id)->decrementEach(['score' => 4]);

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(6, (int) $aliceAfter->score);
    }

    #[Test]
    public function update_or_insert_invalidates_cache(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id); // warm entry

        User::query()->updateOrInsert(
            ['email' => 'alice@example.com'],
            ['score' => 77],
        );

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(77, (int) $aliceAfter->score, 'cache must reflect the updateOrInsert');
    }

    #[Test]
    public function builder_touch_invalidates_cached_updated_at(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($alice->id);

        $originalUpdatedAt = (string) $alice->updated_at;
        Sleep::sleep(1);

        User::where('id', $alice->id)->touch();

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $fromDb = User::withoutIdentityMap()->find($alice->id);
        $this->assertInstanceOf(User::class, $fromDb);
        $this->assertNotSame($originalUpdatedAt, (string) $fromDb->updated_at, 'DB updated_at must have advanced');
        $this->assertSame(
            (string) $fromDb->updated_at,
            (string) $aliceAfter->updated_at,
            'cached updated_at must match the SQL UPDATE issued by Builder::touch()',
        );
    }

    #[Test]
    public function insert_or_ignore_invalidates_coverage_for_model_class(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $alice->id, 'title' => 'P1', 'published' => true]);

        // Warm coverage.
        $coveredBefore = Post::where('published', true)->get();
        $this->assertCount(1, $coveredBefore);

        Post::insertOrIgnore([
            ['user_id' => $alice->id, 'title' => 'P2', 'published' => true, 'view_count' => 0],
        ]);

        $coveredAfter = Post::where('published', true)->get();
        $this->assertCount(2, $coveredAfter, 'coverage must be invalidated by insertOrIgnore');
    }

    #[Test]
    public function insert_using_invalidates_coverage_for_model_class(): void
    {
        // Seed a Tag we can mirror into Post via insertUsing.
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Tag::create(['name' => 'tagA', 'priority' => 1]);
        Post::create(['user_id' => $alice->id, 'title' => 'P-existing', 'published' => true]);

        $coveredBefore = Post::where('published', true)->get();
        $this->assertCount(1, $coveredBefore);

        // Use insertUsing to add a row sourced from another query.
        Post::insertUsing(
            ['user_id', 'title', 'published', 'view_count', 'created_at', 'updated_at'],
            fn ($q) => $q->from('tags')->selectRaw('? as user_id, name as title, true as published, 0 as view_count, ? as created_at, ? as updated_at', [
                $alice->id, now()->toDateTimeString(), now()->toDateTimeString(),
            ]),
        );

        $coveredAfter = Post::where('published', true)->get();
        $this->assertCount(2, $coveredAfter, 'coverage must be invalidated by insertUsing');
    }

    #[Test]
    public function insert_invalidates_coverage_for_model_class(): void
    {
        // Pre-warm coverage: AND([]) over Post with PKs from the seeded rows.
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $alice->id, 'title' => 'P1', 'published' => true]);
        $countBefore = Post::count();
        $this->assertSame(1, $countBefore);

        // Warm coverage region (Post::all-equivalent).
        $coveredBefore = Post::where('published', true)->get();
        $this->assertCount(1, $coveredBefore);

        // Raw insert via Eloquent Builder — no model events fire.
        Post::insert([
            ['user_id' => $alice->id, 'title' => 'P2', 'published' => true, 'view_count' => 0],
        ]);

        // The new row must appear in the next query, not the stale coverage list.
        $coveredAfter = Post::where('published', true)->get();
        $this->assertCount(2, $coveredAfter, 'coverage must be invalidated by raw insert');
    }

    #[Test]
    public function upsert_invalidates_cached_entries_for_overlapping_rows(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        User::find($alice->id);

        User::upsert(
            [['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 99]],
            ['email'],
            ['score'],
        );

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);
        $this->assertSame(99, (int) $aliceAfter->score, 'cache must reflect the upserted score');
    }

    #[Test]
    public function mass_update_keeps_cached_updated_at_in_sync_with_database(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        User::find($alice->id); // warm entry

        $originalUpdatedAt = (string) $alice->updated_at;
        Sleep::sleep(1); // ensure freshTimestampString changes

        User::where('active', false)->update(['active' => true]);

        $aliceAfter = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceAfter);

        $fromDb = User::withoutIdentityMap()->find($alice->id);
        $this->assertInstanceOf(User::class, $fromDb);

        $this->assertNotSame($originalUpdatedAt, (string) $fromDb->updated_at, 'DB updated_at must have advanced');
        $this->assertSame(
            (string) $fromDb->updated_at,
            (string) $aliceAfter->updated_at,
            'cache updated_at must match the SQL UPDATE that just ran',
        );
    }

    #[Test]
    public function mass_increment_flushes_coverage_and_graph_for_model_class(): void
    {
        $graph = resolve(IdentityGraph::class);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'score' => 10]);
        Post::create(['user_id' => $alice->id, 'title' => 'P1', 'published' => true]);
        User::where('score', 10)->get();
        $alice->load('posts');

        $this->assertGreaterThan(0, $this->registry->entryCount(),
            'coverage must be warmed before the bulk write');
        $this->assertGreaterThan(0, $graph->edgeCount(),
            'graph must have at least one edge before the bulk write');

        User::where('id', $alice->id)->increment('score');

        $this->assertSame(0, $this->registry->entryCount(),
            'increment must flush coverage entries for the User class');
        $this->assertSame(0, $graph->edgeCount(),
            'increment must invalidate graph edges that target the User class');
    }

    #[Test]
    public function delete_with_non_extractable_predicate_flushes_identity_store(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $p1 = Post::create(['user_id' => $alice->id, 'title' => 'P1', 'published' => true]);
        $p2 = Post::create(['user_id' => $alice->id, 'title' => 'P2', 'published' => true]);
        Post::find($p1->id);
        Post::find($p2->id);

        $entriesBefore = $this->store->debugStats()['entries'];
        $this->assertGreaterThanOrEqual(2, $entriesBefore);

        // whereRaw produces a `type='raw'` where with no column, so
        // QueryPatternExtractor cannot build a phase-one predicate and
        // IdentityMapBuilder::delete() takes the no-predicate else branch
        // that calls $store->flush($modelClass) directly. Post is a hard-delete
        // model, so parent::delete() does not loop back through
        // IdentityMapBuilder::update() and the else-branch flush is observable.
        Post::whereRaw('id = ?', [$p1->id])->delete();

        $this->assertLessThan($entriesBefore, $this->store->debugStats()['entries'],
            'delete on a non-extractable predicate must flush Post entries from the identity store');
    }
}
