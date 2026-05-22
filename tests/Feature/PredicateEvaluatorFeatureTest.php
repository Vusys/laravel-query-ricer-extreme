<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\AttributeFact;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class PredicateEvaluatorFeatureTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    private function createFresh(string $name, string $email, bool $active = true): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'active' => $active]);
        $this->store->flush();

        return $user;
    }

    // -----------------------------------------------------------------------
    // Basic equality pruning
    // -----------------------------------------------------------------------

    #[Test]
    public function match_served_from_memory_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Both models known; predicate pruning should issue no SQL');
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($alice->id, $first->id);
    }

    #[Test]
    public function reject_excluded_without_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', false)->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($bob->id, $first->id);
    }

    #[Test]
    public function all_rejected_returns_empty_no_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: true);

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', false)->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(0, $result);
    }

    // -----------------------------------------------------------------------
    // Partial model safety — unknown attributes cause DB query
    // -----------------------------------------------------------------------

    #[Test]
    public function partial_model_unknown_attribute_falls_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $this->store->flush();

        // Load with partial columns — 'active' not selected
        User::select('id', 'name')->whereKey($alice->id)->first();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // 'active' is unknown in memory → cannot evaluate → must query
        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(1, $queryCount, 'Unknown attribute must trigger a SQL query');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function unknown_key_always_queried(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame(1, $queryCount, 'Unknown key must trigger a SQL query');
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($alice->id, $first->id);
    }

    // -----------------------------------------------------------------------
    // IN / NOT IN predicates
    // -----------------------------------------------------------------------

    #[Test]
    public function where_in_matches_model_in_list(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->whereIn('name', ['Alice'])->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame('Alice', $first->name);
    }

    #[Test]
    public function where_not_in_excludes_matching_models(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->whereNotIn('name', ['Alice'])->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame('Bob', $first->name);
    }

    // -----------------------------------------------------------------------
    // NULL / NOT NULL predicates
    // -----------------------------------------------------------------------

    #[Test]
    public function where_null_matches_model_with_null_value(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $aliceEntry = $this->store->find(
            connection: $alice->getConnectionName() ?? 'default',
            modelClass: User::class,
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: $alice->id,
            fingerprint: 'soft-delete:default',
        );
        $this->assertNotNull($aliceEntry);
        $aliceEntry->attributes->facts['manager_id'] = new AttributeFact(
            column: 'manager_id',
            originalValue: null,
            currentValue: null,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );

        $bobEntry = $this->store->find(
            connection: $bob->getConnectionName() ?? 'default',
            modelClass: User::class,
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: $bob->id,
            fingerprint: 'soft-delete:default',
        );
        $this->assertNotNull($bobEntry);
        $bobEntry->attributes->facts['manager_id'] = new AttributeFact(
            column: 'manager_id',
            originalValue: 42,
            currentValue: 42,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->whereNull('manager_id')->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($alice->id, $first->id);
    }

    #[Test]
    public function predicates_after_safe_scope_where_are_still_evaluated(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // whereNull('deleted_at') is placed before where('active') so that a
        // safe-scope where appears between the key-set IN clause and the
        // predicate in the wheres array. The loop must continue (not break) on
        // seeing the safe scope so the subsequent predicate is still extracted.
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereNull('deleted_at')
            ->where('active', true)
            ->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame($alice->id, $first->id);
    }

    // -----------------------------------------------------------------------
    // Compound predicates
    // -----------------------------------------------------------------------

    #[Test]
    public function multiple_where_clauses_all_evaluated(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: true);
        $charlie = $this->createFresh('Charlie', 'charlie@example.com', active: false);

        User::find($alice->id);
        User::find($bob->id);
        User::find($charlie->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id, $charlie->id])
            ->where('active', true)
            ->where('name', 'Alice')
            ->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame('Alice', $first->name);
    }

    // -----------------------------------------------------------------------
    // Identity — matched model is the same PHP instance
    // -----------------------------------------------------------------------

    #[Test]
    public function matched_model_is_same_instance(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        $cachedAlice = User::find($alice->id);
        User::find($bob->id);

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame($cachedAlice, $result->first());
    }

    // -----------------------------------------------------------------------
    // Not-equal operator
    // -----------------------------------------------------------------------

    #[Test]
    public function not_equal_operator_prunes_correctly(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('name', '!=', 'Bob')->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);

        $first = $result->first();
        $this->assertNotNull($first);
        $this->assertSame('Alice', $first->name);
    }

    // -----------------------------------------------------------------------
    // Unsupported predicates fall through to SQL
    // -----------------------------------------------------------------------

    #[Test]
    public function unsupported_predicate_falls_through_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $bob = $this->createFresh('Bob', 'bob@example.com');

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // LIKE is not a supported M3 predicate operator
        $result = User::whereKey([$alice->id, $bob->id])->where('name', 'LIKE', 'Ali%')->get();

        $this->assertSame(1, $queryCount, 'Unsupported operator must fall through to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function raw_where_falls_through_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->whereRaw("name = 'Alice'")->get();

        $this->assertSame(1, $queryCount, 'Raw where must fall through to SQL');
        $this->assertCount(1, $result);
    }

    // -----------------------------------------------------------------------
    // Mixed: some keys in memory, some not; predicate evaluation
    // -----------------------------------------------------------------------

    #[Test]
    public function mix_of_match_reject_unknown_key(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);
        $charlie = $this->createFresh('Charlie', 'charlie@example.com', active: true);

        User::find($alice->id);  // in map: active=true → match
        User::find($bob->id);   // in map: active=false → reject
        // Charlie not in map: unknown key → must query

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id, $charlie->id])
            ->where('active', true)
            ->get();

        $this->assertSame(1, $queryCount, 'Unknown key triggers a rewrite query');
        $this->assertCount(2, $result);

        $ids = $result->pluck('id')->toArray();
        $this->assertContains($alice->id, $ids);
        $this->assertContains($charlie->id, $ids);
        $this->assertNotContains($bob->id, $ids);
    }

    #[Test]
    public function order_preserved_with_predicate_pruning(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: true);
        $charlie = $this->createFresh('Charlie', 'charlie@example.com', active: true);

        User::find($alice->id);
        User::find($bob->id);
        User::find($charlie->id);

        $result = User::whereKey([$charlie->id, $alice->id, $bob->id])
            ->where('active', true)
            ->get();

        $this->assertCount(3, $result);

        $item0 = $result->get(0);
        $item1 = $result->get(1);
        $item2 = $result->get(2);
        $this->assertNotNull($item0);
        $this->assertNotNull($item1);
        $this->assertNotNull($item2);
        $this->assertSame($charlie->id, $item0->id);
        $this->assertSame($alice->id, $item1->id);
        $this->assertSame($bob->id, $item2->id);
    }
}
