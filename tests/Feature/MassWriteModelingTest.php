<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
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
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

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
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        User::find($alice->id);
        User::find($bob->id);

        $this->assertSame(2, $this->store->debugStats()['entries']);

        User::withTrashed()->where('active', false)->forceDelete();

        $this->assertSame(0, $this->store->debugStats()['entries'],
            'forceDelete must flush all User entries from the identity store');
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
}
