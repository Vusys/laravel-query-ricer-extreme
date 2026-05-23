<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class IdentityMapTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    #[Test]
    public function find_returns_same_instance(): void
    {
        $userA = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $userB = User::find($userA->id);
        $userC = User::find($userA->id);

        $this->assertSame($userB, $userC);
    }

    #[Test]
    public function find_returns_same_instance_as_created(): void
    {
        $created = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $found = User::find($created->id);

        $this->assertSame($created, $found);
    }

    #[Test]
    public function second_find_issues_no_query(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);
        User::find($user->id);

        $this->assertSame(0, $queryCount, 'Second find should issue no SQL');
    }

    #[Test]
    public function where_key_first_returns_same_instance(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $fromWhereKey = User::query()->whereKey($user->id)->first();

        $this->assertSame($user, $fromWhereKey);
    }

    #[Test]
    public function where_key_first_issues_no_query_after_find(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->whereKey($user->id)->first();

        $this->assertSame(0, $queryCount, 'whereKey()->first() should not issue SQL when model is mapped');
    }

    #[Test]
    public function find_returns_null_for_nonexistent_id(): void
    {
        $result = User::find(9999);

        $this->assertNull($result);
    }

    #[Test]
    public function find_nonexistent_id_is_tracked_as_absent(): void
    {
        User::find(9999);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find(9999);

        $this->assertNull($result);
        $this->assertSame(0, $queryCount, 'Second find for absent key should issue no SQL');
    }

    #[Test]
    public function where_key_first_tracks_absent_key(): void
    {
        User::query()->whereKey(9999)->first();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find(9999);

        $this->assertNull($result);
        $this->assertSame(0, $queryCount, 'find() after whereKey miss should use absent tracking');
    }

    #[Test]
    public function flush_clears_all_entries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user = User::find($alice->id);
        $this->assertInstanceOf(User::class, $user);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after flush should issue SQL');
    }

    #[Test]
    public function flush_for_model_class_only_clears_that_class(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $this->store->flush(User::class);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after model-class flush should issue SQL');
    }

    #[Test]
    public function forget_removes_specific_model(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $this->store->forget($user);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after forget should issue SQL');
    }

    #[Test]
    public function without_identity_map_bypasses_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->withoutIdentityMap()->find($user->id);

        $this->assertSame(1, $queryCount, 'withoutIdentityMap() find should always issue SQL');
    }

    #[Test]
    public function without_identity_map_does_not_affect_subsequent_finds(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        User::query()->withoutIdentityMap()->find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(0, $queryCount, 'withoutIdentityMap() should not disable the store globally');
    }

    #[Test]
    public function disabled_scope_bypasses_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->store->disabled(function () use ($user): void {
            User::find($user->id);
        });

        $this->assertSame(1, $queryCount, 'disabled() scope should always issue SQL');
    }

    #[Test]
    public function disabled_scope_restores_store_after_callback(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $this->store->disabled(function (): void {});

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(0, $queryCount, 'Store should be re-enabled after disabled() callback completes');
    }

    #[Test]
    public function saved_model_updates_entry(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $found = User::find($user->id);
        $this->assertInstanceOf(User::class, $found);

        $found->name = 'Alicia';
        $found->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $again = User::find($user->id);
        $this->assertInstanceOf(User::class, $again);

        $this->assertSame(0, $queryCount, 'Find after save should still use map');
        $this->assertSame('Alicia', $again->name);
    }

    #[Test]
    public function soft_deleted_model_returns_null_from_default_scope(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $user->delete();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($user->id);

        $this->assertNull($result);
    }

    #[Test]
    public function restored_model_is_served_from_map_without_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user->delete();
        $user->restore();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($user->id);

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(0, $queryCount, 'Restored model should be served from map without SQL');
    }

    #[Test]
    public function force_deleted_model_is_not_served_from_map(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $id = $user->id;
        User::find($id);

        $user->forceDelete();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($id);

        $this->assertNull($result);
        $this->assertSame(1, $queryCount, 'Force-deleted model should not be served from map');
    }

    #[Test]
    public function explain_returns_explanation_for_memory_hit(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $explanations = $this->store->explain(function () use ($user): void {
            User::find($user->id);
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('return_model_from_memory', $explanations[0]->type->value);
        $this->assertFalse($explanations[0]->sqlExecuted);
    }

    #[Test]
    public function explain_returns_explanation_for_sql_execution(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->store->flush();

        $explanations = $this->store->explain(function () use ($user): void {
            User::find($user->id);
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('execute_normally', $explanations[0]->type->value);
        $this->assertTrue($explanations[0]->sqlExecuted);
    }

    #[Test]
    public function explain_restores_capturing_state_after_callback(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $outer = $this->store->explain(function () use ($user): void {
            // Inner explain consumes its own events and restores captured to empty.
            $inner = $this->store->explain(function () use ($user): void {
                User::find($user->id);
            });
            $this->assertCount(1, $inner);
            // This find is captured by the outer explain (inner already finished).
            User::find($user->id);
        });

        $this->assertCount(1, $outer);
        $this->assertFalse($this->store->isCapturing(), 'Capturing state should be false after explain() returns');
    }

    #[Test]
    public function merge_from_saved_resets_provenance_and_dirty_flag(): void
    {
        $knowledge = new AttributeKnowledge;
        $stale = new AttributeFact(
            column: 'name',
            originalValue: 'Old',
            currentValue: 'Dirty',
            isDirty: true,
            confidence: FactConfidence::Assumed,
            source: FactSource::AssignedInMemory,
        );
        $knowledge->set('name', $stale);

        $user = User::create(['name' => 'Saved', 'email' => 'saved@example.com']);
        $knowledge->mergeFromSaved($user);

        $fact = $knowledge->get('name');
        $this->assertNotNull($fact);
        $this->assertFalse($fact->isDirty);
        $this->assertSame(FactConfidence::Certain, $fact->confidence);
        $this->assertSame(FactSource::HydratedFromDatabase, $fact->source);
        $this->assertSame('Saved', $fact->currentValue);
    }

    #[Test]
    public function store_is_singleton(): void
    {
        $storeA = resolve(IdentityMapStore::class);
        $storeB = resolve(IdentityMapStore::class);

        $this->assertSame($storeA, $storeB);
    }

    #[Test]
    public function unqualified_pk_where_uses_map(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->where('id', '=', $user->id)->first();

        $this->assertSame(0, $queryCount, 'Explicit unqualified id= where should use the map');
    }

    #[Test]
    public function or_where_null_on_deleted_at_falls_through_to_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->where('id', $user->id)->orWhereNull('deleted_at')->first();

        $this->assertSame(1, $queryCount, 'orWhereNull(deleted_at) changes query semantics — boolean=or must not be treated as a safe soft-delete scope');
    }

    #[Test]
    public function unqualified_deleted_at_where_null_is_recognised_as_safe_scope(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::query()->where('id', $user->id)->whereNull('deleted_at')->first();

        $this->assertInstanceOf(User::class, $result);
        $this->assertSame(0, $queryCount, 'Unqualified whereNull(deleted_at) must be recognised as a safe scope so the map shortcut fires');
    }

    #[Test]
    public function where_clause_with_extra_filter_falls_through_to_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->where('id', $user->id)->where('name', 'Alice')->first();

        $this->assertSame(1, $queryCount, 'Non-safe additional where should fall through to SQL');
    }

    #[Test]
    public function multiple_pk_wheres_fall_through_to_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->where('id', $user->id)->where('id', $user->id)->first();

        $this->assertSame(1, $queryCount, 'Two PK where clauses should fall through to SQL');
    }

    #[Test]
    public function non_equal_operator_on_pk_falls_through_to_sql(): void
    {
        $user1 = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user2 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        User::find($user1->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::query()->where('id', '!=', $user1->id)->orderBy('id')->first();

        $this->assertSame(1, $queryCount, 'Non-equal operator should not extract PK for map lookup');
        $this->assertSame($user2->id, $result?->id);
    }

    #[Test]
    public function without_identity_map_does_not_mutate_original_builder(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $builder = User::query();
        $builder->withoutIdentityMap();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $builder->find($user->id);

        $this->assertSame(0, $queryCount, 'withoutIdentityMap() must not disable the map on the original builder');
    }

    #[Test]
    public function models_are_not_remembered_during_disabled_scope(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->store->flush();

        $this->store->disabled(function () use ($user): void {
            User::find($user->id);
        });

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Models retrieved in a disabled scope must not be written to the map');
    }

    #[Test]
    public function disabled_restores_state_when_callback_throws(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        try {
            $this->store->disabled(function (): never {
                throw new \RuntimeException('intentional');
            });
        } catch (\RuntimeException) {
        }

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(0, $queryCount, 'disabled() must restore the previous state even when the callback throws');
    }

    #[Test]
    public function explain_restores_capturing_state_when_callback_throws(): void
    {
        try {
            $this->store->explain(function (): never {
                throw new \RuntimeException('intentional');
            });
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException) {
        }

        $this->assertFalse($this->store->isCapturing(), 'explain() must restore capturing state even when the callback throws');
    }

    #[Test]
    public function fallthrough_sql_marks_all_columns_known(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->store->flush();

        // Non-PK WHERE falls through to SQL. The returned model must be marked allColumnsKnown
        // so that a subsequent find() can serve it from memory without re-querying.
        User::where('name', 'Alice')->get();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($alice->id);

        $this->assertSame(0, $queryCount, 'Model returned from fallthrough SQL must be marked all-columns-known and served from memory');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function flush_for_model_class_clears_absent_entries(): void
    {
        User::find(9999);

        $this->store->flush(User::class);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find(9999);

        $this->assertSame(1, $queryCount, 'flush(ModelClass) must clear absent entries for that class so the next find() issues SQL');
    }

    #[Test]
    public function debug_stats_returns_current_counts(): void
    {
        $stats = $this->store->debugStats();

        $this->assertSame(0, $stats['entries']);
        $this->assertSame(0, $stats['absent']);
        $this->assertFalse($stats['disabled']);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find(9999);

        $stats = $this->store->debugStats();

        $this->assertSame(1, $stats['entries']);
        $this->assertSame(1, $stats['absent']);
        $this->assertFalse($stats['disabled']);

        $this->store->disabled(function (): void {
            $inner = $this->store->debugStats();
            $this->assertTrue($inner['disabled']);
        });

        unset($alice);
    }

    #[Test]
    public function where_key_get_returns_empty_collection_for_absent_tracked_key(): void
    {
        User::find(9999);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey(9999)->get();

        $this->assertSame(0, $queryCount, 'Absent-tracked key should produce empty collection without SQL via get()');
        $this->assertCount(0, $result);
    }

    #[Test]
    public function find_with_non_scalar_id_delegates_to_where_key(): void
    {
        $result = User::find(null);

        $this->assertNull($result);
    }

    #[Test]
    public function find_via_sql_marks_all_columns_known_for_subsequent_find(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $this->store->flush();

        // First find goes to SQL (alice not in map); must mark all columns known on the returned model.
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Second find must be served from memory — impossible without allColumnsKnown being set.
        $result = User::find($alice->id);

        $this->assertSame(0, $queryCount, 'find() after SQL fetch must mark model all-columns-known so second find() needs no SQL');
        $this->assertInstanceOf(User::class, $result);
    }

    #[Test]
    public function explain_single_pk_via_where_returns_collection_from_memory(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $explanations = $this->store->explain(function () use ($alice): void {
            User::query()->whereKey($alice->id)->get();
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('return_collection_from_memory', $explanations[0]->type->value);
        $this->assertFalse($explanations[0]->sqlExecuted);
        $this->assertContains($alice->id, $explanations[0]->memoryKeys);
    }

    #[Test]
    public function explanation_to_string_includes_plan_model_and_sql_executed(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($alice->id);

        $explanations = $this->store->explain(function () use ($alice): void {
            User::find($alice->id);
        });

        $this->assertCount(1, $explanations);
        $string = (string) $explanations[0];
        $this->assertStringContainsString('Plan:', $string);
        $this->assertStringContainsString('Model:', $string);
        $this->assertStringContainsString('SQL executed: no', $string);
    }

    #[Test]
    public function with_trashed_find_returns_soft_deleted_model_from_memory_without_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user->delete();

        // First withTrashed query hits SQL and warms the map under the with-trashed fingerprint.
        $firstResult = User::withTrashed()->find($user->id);
        $this->assertInstanceOf(User::class, $firstResult);
        $this->assertNotNull($firstResult->deleted_at);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Second withTrashed query must be served from memory without SQL.
        $secondResult = User::withTrashed()->find($user->id);

        $this->assertSame(0, $queryCount, 'withTrashed()->find() should be served from memory after the first SQL call');
        $this->assertInstanceOf(User::class, $secondResult);
        $this->assertSame($firstResult, $secondResult);
    }

    #[Test]
    public function remember_does_not_store_a_model_that_does_not_exist(): void
    {
        $model = new User;
        // A new model instance that has never been persisted has exists = false.
        $this->store->remember($model);

        $this->assertSame(0, $this->store->debugStats()['entries']);
    }

    // -----------------------------------------------------------------------
    // Structural hazards always bypass the identity map
    // -----------------------------------------------------------------------

    #[Test]
    public function find_with_lock_always_hits_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::where('id', $user->id)->lockForUpdate()->first();

        $this->assertSame(1, $queryCount, 'A locking query must always hit SQL to acquire the lock');
    }

    #[Test]
    public function wherekey_with_join_always_hits_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::join('users as u2', 'u2.id', '=', 'users.id')->whereKey([$user->id])->get();

        $this->assertSame(1, $queryCount, 'A keyset query with a join must hit SQL');
    }

    #[Test]
    public function single_pk_lookup_with_join_always_hits_sql(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::join('users as u2', 'u2.id', '=', 'users.id')->where('users.id', $user->id)->first();

        $this->assertSame(1, $queryCount, 'A single-PK lookup with a join must hit SQL');
    }

    #[Test]
    public function keyset_with_limit_always_hits_sql(): void
    {
        $user1 = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user2 = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        User::whereKey([$user1->id, $user2->id])->get();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $results = User::whereKey([$user1->id, $user2->id])->limit(1)->get();

        $this->assertSame(1, $queryCount, 'A keyset query with LIMIT must hit SQL');
        $this->assertCount(1, $results);
    }
}
