<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ProcessTruthTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
        config(['query-ricer-extreme.attribute_truth' => 'process_truth']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        config(['query-ricer-extreme.attribute_truth' => 'database_only']);
        parent::tearDown();
    }

    private function createFresh(string $name, string $email, bool $active = true): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'active' => $active]);
        $this->store->flush();

        return $user;
    }

    // -----------------------------------------------------------------------
    // Dirty predicate evaluation — bounded key-set path
    // -----------------------------------------------------------------------

    #[Test]
    public function dirty_model_rejected_when_predicate_no_longer_matches(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        User::find($bob->id);

        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Both models known; no SQL expected even in process-truth mode');
        $this->assertCount(0, $result, 'Alice is dirty-false, Bob is false — no match');
    }

    #[Test]
    public function dirty_model_matched_when_predicate_matches_current_value(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: false);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = true;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Model known; predicate matches dirty value — no SQL');
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function both_dirty_true_no_sql_needed(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        User::find($alice->id);
        $bobLive = User::find($bob->id);
        $this->assertInstanceOf(User::class, $bobLive);
        $bobLive->active = true;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Both dirty-true; no SQL needed');
        $this->assertCount(2, $result);
    }

    // -----------------------------------------------------------------------
    // database_only mode — dirty changes must NOT affect evaluation
    // -----------------------------------------------------------------------

    #[Test]
    public function database_only_mode_ignores_dirty_changes(): void
    {
        config(['query-ricer-extreme.attribute_truth' => 'database_only']);

        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Original value is true — no SQL needed');
        $this->assertCount(1, $result, 'database_only: original value used, dirty ignored');
    }

    // -----------------------------------------------------------------------
    // Save reconciliation
    // -----------------------------------------------------------------------

    #[Test]
    public function after_save_original_value_updated_predicate_reflects_new_state(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = false;
        $aliceLive->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'All known — no SQL');
        $this->assertCount(0, $result, 'After save, original is false — query for active=true returns nothing');
    }

    #[Test]
    public function after_save_updated_value_matches_query(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = false;
        $aliceLive->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', false)->get();

        $this->assertSame(0, $queryCount, 'All known — no SQL');
        $this->assertCount(1, $result, 'After save, original is false — matches active=false query');
    }

    #[Test]
    public function save_reconciles_string_attribute(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->name = 'Alice Updated';
        $aliceLive->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('name', 'Alice Updated')->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);
    }

    // -----------------------------------------------------------------------
    // Mutation-aware coverage filtering
    // -----------------------------------------------------------------------

    #[Test]
    public function dirty_mutation_excludes_model_from_coverage_result(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: true);

        $models = User::where('active', true)->get();
        $aliceLive = $models->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Coverage hit — no SQL');
        $this->assertCount(1, $result, 'Alice is dirty-false, only Bob matches');
        $this->assertSame($bob->id, $result->first()?->id);
    }

    #[Test]
    public function dirty_mutation_excludes_model_from_coverage_when_no_longer_matches(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: false);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        $models = User::where('active', false)->get();
        $aliceLive = $models->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        $aliceLive->active = true;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('active', false)->get();

        $this->assertSame(0, $queryCount, 'Coverage hit — no SQL');
        $this->assertCount(1, $result, 'Alice is dirty-true so no longer matches active=false; only Bob');
        $this->assertSame($bob->id, $result->first()?->id);
    }

    #[Test]
    public function database_only_mode_ignores_dirty_in_coverage_filter(): void
    {
        config(['query-ricer-extreme.attribute_truth' => 'database_only']);

        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $this->createFresh('Bob', 'bob@example.com', active: true);

        $models = User::where('active', true)->get();
        $aliceLive = $models->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Coverage hit — no SQL');
        $this->assertCount(2, $result, 'database_only: dirty mutation ignored, both models returned');
    }

    // -----------------------------------------------------------------------
    // Unique-key path: stale unique-key invalidation under process-truth
    // -----------------------------------------------------------------------

    #[Test]
    public function unique_key_lookup_misses_when_column_dirty_in_process_truth(): void
    {
        config([
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);

        $alice = $this->createFresh('Alice', 'alice@example.com');

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->email = 'changed@example.com';

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->get();

        $this->assertSame(1, $queryCount, 'Unique key is stale in process-truth — must hit SQL');
        $this->assertCount(1, $result, 'DB still has old email; SQL returns it');
    }

    #[Test]
    public function unique_key_lookup_still_hits_when_column_unchanged(): void
    {
        config([
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);

        $alice = $this->createFresh('Alice', 'alice@example.com');

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->get();

        // process-truth always bypasses the unique-key cache to avoid drift-in correctness issues
        $this->assertSame(1, $queryCount, 'process-truth always falls through for unique-key lookups');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function unique_key_lookup_bypasses_stale_absence_in_process_truth(): void
    {
        $this->setupStaleAbsenceScenario();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'new@example.com')->get();

        $this->assertSame(1, $queryCount, 'stale absence cache must be bypassed in process-truth');
        $this->assertCount(0, $result);
    }

    #[Test]
    public function exists_bypasses_stale_absence_in_process_truth(): void
    {
        $this->setupStaleAbsenceScenario();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'new@example.com')->exists();

        $this->assertSame(1, $queryCount, 'stale absence cache must be bypassed in process-truth for exists()');
        $this->assertFalse($exists);
    }

    /** Configures unique-key index, creates Alice, primes the absence cache for 'new@example.com', then dirties Alice's email to that value (drift-in). */
    private function setupStaleAbsenceScenario(): void
    {
        config([
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);

        $alice = $this->createFresh('Alice', 'alice@example.com');

        User::find($alice->id);

        User::where('email', 'new@example.com')->get();

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->email = 'new@example.com';
    }

    // -----------------------------------------------------------------------
    // Dirty whereIn evaluation
    // -----------------------------------------------------------------------

    #[Test]
    public function dirty_value_evaluated_in_wherein_predicate(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->name = 'Renamed';

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->whereIn('name', ['Renamed', 'Other'])->get();

        $this->assertSame(0, $queryCount, 'Dirty value matches whereIn — no SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function dirty_value_evaluated_in_where_not_in_predicate(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->name = 'Renamed';

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->whereNotIn('name', ['Alice', 'Other'])->get();

        $this->assertSame(0, $queryCount, 'Dirty name not in original list — match via process-truth');
        $this->assertCount(1, $result);
    }
}
