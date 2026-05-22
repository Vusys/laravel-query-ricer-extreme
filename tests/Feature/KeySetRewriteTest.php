<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class KeySetRewriteTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    // Helper: create a user without populating the identity map.
    private function createFresh(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email]);
        $this->store->flush();

        return $user;
    }

    #[Test]
    public function where_key_array_all_in_memory_issues_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->get();

        $this->assertSame(0, $queryCount, 'whereKey with all keys in memory should issue no SQL');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function where_key_array_all_in_memory_returns_same_instances(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        $foundAlice = User::find($alice->id);
        $foundBob = User::find($bob->id);

        $result = User::whereKey([$alice->id, $bob->id])->get();

        $this->assertSame($foundAlice, $result->find($alice->id));
        $this->assertSame($foundBob, $result->find($bob->id));
    }

    #[Test]
    public function where_key_array_partial_memory_hit_rewrites_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        $cachedAlice = User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->get();

        $this->assertSame(1, $queryCount, 'Should issue exactly one SQL query for the unknown key');
        $this->assertCount(2, $result);
        $this->assertSame($cachedAlice, $result->find($alice->id), 'Alice must come from memory, not from SQL');
    }

    #[Test]
    public function where_key_array_partial_memory_hit_returns_same_instance_for_hit(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        $cachedAlice = User::find($alice->id);

        $result = User::whereKey([$alice->id, $bob->id])->get();

        $this->assertSame($cachedAlice, $result->find($alice->id));
    }

    #[Test]
    public function where_key_array_no_memory_hits_executes_normally(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->get();

        $this->assertSame(1, $queryCount);
        $this->assertCount(2, $result);
    }

    #[Test]
    public function where_key_array_preserves_input_key_order(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');
        $charlie = $this->createFresh('Charlie', 'charlie@example.com');

        User::find($alice->id);
        User::find($charlie->id);

        $result = User::whereKey([$charlie->id, $alice->id, $bob->id])->get();

        $this->assertCount(3, $result);
        $this->assertSame([$charlie->id, $alice->id, $bob->id], $result->pluck('id')->all());
    }

    #[Test]
    public function where_key_array_all_absent_returns_empty_without_sql(): void
    {
        User::find(9991);
        User::find(9992);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([9991, 9992])->get();

        $this->assertSame(0, $queryCount, 'All-absent key set should issue no SQL');
        $this->assertCount(0, $result);
    }

    #[Test]
    public function where_key_array_mixed_absent_and_memory_hit_issues_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);
        User::find(9999);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, 9999])->get();

        $this->assertSame(0, $queryCount, 'Mixed hit+absent key set should issue no SQL');
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function where_key_array_nonexistent_key_is_tracked_as_absent(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        User::whereKey([$alice->id, 9999])->get();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find(9999);

        $this->assertNull($result);
        $this->assertSame(0, $queryCount, 'Key absent from key-set SQL result should be tracked and skip subsequent SQL');
    }

    #[Test]
    public function find_many_all_in_memory_issues_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::findMany([$alice->id, $bob->id]);

        $this->assertSame(0, $queryCount, 'findMany with all keys in memory should issue no SQL');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function find_many_partial_memory_hit_rewrites_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::findMany([$alice->id, $bob->id]);

        $this->assertSame(1, $queryCount, 'findMany should issue SQL for unknown keys only');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function find_with_array_all_in_memory_issues_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find([$alice->id, $bob->id]);

        $this->assertSame(0, $queryCount, 'find() with array of known keys should issue no SQL');
    }

    #[Test]
    public function where_in_on_primary_key_uses_key_set_rewrite(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereIn('id', [$alice->id, $bob->id])->get();

        $this->assertSame(1, $queryCount, 'whereIn on PK should use key-set rewrite');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function soft_deleted_key_treated_as_absent_in_key_set(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $bob->delete();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->get();

        $this->assertSame(0, $queryCount, 'Soft-deleted key should be treated as absent in key-set — no SQL needed');
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function explain_returns_return_collection_from_memory_for_all_known_key_set(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $explanations = $this->store->explain(function () use ($alice, $bob): void {
            User::whereKey([$alice->id, $bob->id])->get();
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('return_collection_from_memory', $explanations[0]->type->value);
        $this->assertFalse($explanations[0]->sqlExecuted);
    }

    #[Test]
    public function explain_returns_rewrite_plan_for_partial_key_set(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);

        $explanations = $this->store->explain(function () use ($alice, $bob): void {
            User::whereKey([$alice->id, $bob->id])->get();
        });

        $this->assertCount(1, $explanations);
        $this->assertSame('rewrite_primary_keys_and_merge', $explanations[0]->type->value);
        $this->assertTrue($explanations[0]->sqlExecuted);
        $this->assertContains($alice->id, $explanations[0]->knownKeys);
        $this->assertContains($bob->id, $explanations[0]->missingKeys);
    }

    #[Test]
    public function key_set_with_extra_non_pk_where_evaluates_predicate_in_memory(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('name', 'Alice')->get();

        $this->assertSame(0, $queryCount, 'Key-set with known models and simple predicate should issue no SQL');
        $this->assertCount(1, $result);
        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame('Alice', $first->name);
    }

    #[Test]
    public function without_identity_map_on_key_set_bypasses_rewrite(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::query()->withoutIdentityMap()->whereKey([$alice->id, $bob->id])->get();

        $this->assertSame(1, $queryCount, 'withoutIdentityMap() must bypass key-set rewrite');
    }

    #[Test]
    public function duplicate_where_in_on_pk_falls_through_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Two IN clauses on the same PK column — cannot safely optimise, must fall through.
        $result = User::whereIn('id', [$alice->id, $bob->id])
            ->whereIn('id', [$alice->id])
            ->get();

        $this->assertSame(1, $queryCount, 'Duplicate PK whereIn must fall through to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function key_set_fetched_model_is_served_from_memory_on_subsequent_find(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);

        // Bob is unknown; partial hit causes SQL rewrite that fetches Bob.
        // The fetched Bob must have allColumnsKnown marked so find() can serve from memory.
        User::whereKey([$alice->id, $bob->id])->get();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::find($bob->id);

        $this->assertSame(0, $queryCount, 'Model fetched during key-set SQL rewrite must be served from memory on subsequent find()');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame($bob->id, $result->id);
    }

    #[Test]
    public function where_in_type_in_all_in_memory_issues_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // whereIn generates an 'In' where type (not 'InRaw' like whereKey does for integers).
        $result = User::whereIn('id', [$alice->id, $bob->id])->get();

        $this->assertSame(0, $queryCount, 'whereIn (type In) with all keys in memory must issue no SQL');
        $this->assertCount(2, $result);
    }
}
