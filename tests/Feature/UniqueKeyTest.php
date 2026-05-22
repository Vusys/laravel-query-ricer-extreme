<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class UniqueKeyTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();

        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [
                    ['email'],
                    ['name'],
                ],
            ],
        ]]);
    }

    private function createFresh(string $name, string $email, bool $active = true): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'active' => $active]);
        $this->store->flush();

        return $user;
    }

    // -----------------------------------------------------------------------
    // Positive hit — single-column unique key
    // -----------------------------------------------------------------------

    #[Test]
    public function first_by_unique_key_hits_memory(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(0, $queryCount, 'Unique-key hit must skip SQL');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function first_by_unique_key_returns_same_instance(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $loaded = User::find($alice->id);

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame($loaded, $result, 'Must be the exact same PHP object from the identity map');
    }

    // -----------------------------------------------------------------------
    // Compound unique key
    // -----------------------------------------------------------------------

    #[Test]
    public function compound_unique_key_hit(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [
                    ['name', 'email'],
                ],
            ],
        ]]);

        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('name', 'Alice')->where('email', 'alice@example.com')->first();

        $this->assertSame(0, $queryCount, 'Compound unique-key hit must skip SQL');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function compound_key_partial_match_does_not_short_circuit(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [
                    ['name', 'email'],
                ],
            ],
        ]]);

        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Only one column of the compound key provided — not a complete unique hit
        $result = User::where('name', 'Alice')->first();

        $this->assertSame(1, $queryCount, 'Partial compound key must not skip SQL');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    // -----------------------------------------------------------------------
    // No hit — falls through to SQL
    // -----------------------------------------------------------------------

    #[Test]
    public function unique_key_miss_executes_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        // Alice is NOT in the identity map — not loaded yet
        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(1, $queryCount, 'Miss must execute SQL');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function no_unique_key_config_falls_through_to_sql(): void
    {
        config(['query-ricer-extreme.models' => []]);

        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(1, $queryCount, 'No unique config must fall through to SQL');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    // -----------------------------------------------------------------------
    // Extra predicate on top of unique key
    // -----------------------------------------------------------------------

    #[Test]
    public function unique_key_hit_with_extra_predicate_match(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->where('active', true)->first();

        $this->assertSame(0, $queryCount, 'Extra predicate matches — still no SQL');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function unique_key_hit_with_extra_predicate_reject(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Alice is active; looking for inactive — unique key guarantees no other row with that email
        $result = User::where('email', 'alice@example.com')->where('active', false)->first();

        $this->assertSame(0, $queryCount, 'Unique key guarantees the only candidate; predicate rejects → null, no SQL');
        $this->assertNull($result);
    }

    #[Test]
    public function unique_key_hit_with_extra_predicate_unknown_falls_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        // Load with partial columns — 'active' not selected, so it will be unknown
        User::select('id', 'name', 'email')->whereKey($alice->id)->first();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // 'active' is unknown → can't evaluate extra predicate → must fall through
        $result = User::where('email', 'alice@example.com')->where('active', true)->first();

        $this->assertSame(1, $queryCount, 'Unknown extra predicate must fall through to SQL');
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------------
    // firstOrFail — unique-key hit
    // -----------------------------------------------------------------------

    #[Test]
    public function first_or_fail_unique_key_hit(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->firstOrFail();

        $this->assertSame(0, $queryCount);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function first_or_fail_absence_tracked_throws(): void
    {
        // Execute a query that returns null, which will record unique-key absence
        $nullResult = User::where('email', 'ghost@example.com')->first();
        $this->assertNull($nullResult);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $this->expectException(ModelNotFoundException::class);
        User::where('email', 'ghost@example.com')->firstOrFail();

        $this->assertSame(0, $queryCount, 'Absence tracked — should throw without SQL');
    }

    // -----------------------------------------------------------------------
    // sole — unique-key hit
    // -----------------------------------------------------------------------

    #[Test]
    public function sole_unique_key_hit(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->sole();

        $this->assertSame(0, $queryCount);
        $this->assertSame($alice->id, $result->id);
    }

    // -----------------------------------------------------------------------
    // exists — unique-key hit
    // -----------------------------------------------------------------------

    #[Test]
    public function exists_unique_key_positive_hit(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'alice@example.com')->exists();

        $this->assertSame(0, $queryCount, 'exists() unique-key hit must skip SQL');
        $this->assertTrue($exists);
    }

    #[Test]
    public function exists_absence_tracked_returns_false(): void
    {
        $nullResult = User::where('email', 'ghost@example.com')->first();
        $this->assertNull($nullResult);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'ghost@example.com')->exists();

        $this->assertSame(0, $queryCount, 'exists() absence tracked must skip SQL');
        $this->assertFalse($exists);
    }

    #[Test]
    public function exists_unique_key_miss_executes_sql(): void
    {
        // Nothing in the map
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'nobody@example.com')->exists();

        $this->assertSame(1, $queryCount, 'exists() miss must execute SQL');
        $this->assertFalse($exists);
    }

    #[Test]
    public function exists_unique_key_with_extra_predicate_reject(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'alice@example.com')->where('active', false)->exists();

        $this->assertSame(0, $queryCount, 'Unique key + rejected predicate → false with no SQL');
        $this->assertFalse($exists);
    }

    // -----------------------------------------------------------------------
    // Absence recording after SQL null result
    // -----------------------------------------------------------------------

    #[Test]
    public function absence_recorded_after_sql_returns_null(): void
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $first = User::where('email', 'ghost@example.com')->first();
        $this->assertNull($first);
        $this->assertSame(1, $queryCount);

        $queryCount = 0;

        $second = User::where('email', 'ghost@example.com')->first();
        $this->assertNull($second);
        $this->assertSame(0, $queryCount, 'Second lookup must use absence record, no SQL');
    }

    #[Test]
    public function absence_not_recorded_when_extra_predicate_present(): void
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Extra predicate — absence cannot be safely inferred for the unique key alone
        $first = User::where('email', 'ghost@example.com')->where('active', true)->first();
        $this->assertNull($first);
        $this->assertSame(1, $queryCount);

        $queryCount = 0;

        $second = User::where('email', 'ghost@example.com')->where('active', true)->first();
        $this->assertNull($second);
        $this->assertSame(1, $queryCount, 'Absence not recorded when extra predicate was present');
    }

    // -----------------------------------------------------------------------
    // Stale index — model saved with different unique key value
    // -----------------------------------------------------------------------

    #[Test]
    public function stale_unique_index_falls_through_to_sql_after_email_change(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        // Change the email — the old index entry becomes stale
        $alice->email = 'new@example.com';
        $alice->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Old email should no longer be in the unique index
        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(1, $queryCount, 'Stale index must fall through to SQL');
        $this->assertNull($result);
    }

    #[Test]
    public function new_unique_key_indexed_after_save(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $alice->email = 'new@example.com';
        $alice->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // New email must be indexed and served from memory
        $result = User::where('email', 'new@example.com')->first();

        $this->assertSame(0, $queryCount, 'New unique key value must be indexed after save');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    // -----------------------------------------------------------------------
    // Absence cleared when model is later created
    // -----------------------------------------------------------------------

    #[Test]
    public function absence_cleared_when_model_remembered(): void
    {
        $null = User::where('email', 'future@example.com')->first();
        $this->assertNull($null);

        // Now the user is created and retrieved (remember() fires)
        $user = User::create(['name' => 'Future', 'email' => 'future@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'future@example.com')->first();

        $this->assertSame(0, $queryCount, 'Absence must be cleared after model was remembered');
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------------
    // Partial model — unknown unique-key column → fall through to SQL
    // -----------------------------------------------------------------------

    #[Test]
    public function partial_model_missing_unique_column_falls_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        // Load without the email column
        User::select('id', 'name')->whereKey($alice->id)->first();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // email is not in the partial load → unique index not populated for email key
        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(1, $queryCount, 'Unknown email in partial model → SQL');
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------------
    // withoutIdentityMap — bypass
    // -----------------------------------------------------------------------

    #[Test]
    public function without_identity_map_bypasses_unique_key(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::withoutIdentityMap()->where('email', 'alice@example.com')->first();

        $this->assertSame(1, $queryCount, 'withoutIdentityMap must bypass unique-key shortcut');
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------------
    // Explain integration
    // -----------------------------------------------------------------------

    #[Test]
    public function explain_captures_unique_key_plan(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $explanations = $this->store->explain(function (): void {
            User::where('email', 'alice@example.com')->first();
        });

        $this->assertNotEmpty($explanations);
        $plan = $explanations[0];
        $this->assertSame(PlanType::ReturnCollectionFromMemory, $plan->type);
        $this->assertSame('unique-key-positive-hit', $plan->reason);
        $this->assertFalse($plan->sqlExecuted);
    }

    #[Test]
    public function explain_captures_exists_unique_key_plan(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $explanations = $this->store->explain(function (): void {
            User::where('email', 'alice@example.com')->exists();
        });

        $this->assertNotEmpty($explanations);
        $plan = $explanations[0];
        $this->assertSame(PlanType::ReturnExistsFromMemory, $plan->type);
        $this->assertFalse($plan->sqlExecuted);
    }
}
