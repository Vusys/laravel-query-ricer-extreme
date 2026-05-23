<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\IdentityMap;
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

        try {
            User::where('email', 'ghost@example.com')->firstOrFail();
            $this->fail('Expected ModelNotFoundException');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, $queryCount, 'Absence tracked — should throw without SQL');
        }
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
    // exists() — extra predicate matches (must be served from memory)
    // -----------------------------------------------------------------------

    #[Test]
    public function exists_unique_key_with_extra_predicate_match(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'alice@example.com')->where('active', true)->exists();

        $this->assertSame(0, $queryCount, 'Unique key + matching extra predicate must not execute SQL');
        $this->assertTrue($exists);
    }

    // -----------------------------------------------------------------------
    // Extra non-equality predicate (IS NULL / IN) on top of unique key
    // -----------------------------------------------------------------------

    #[Test]
    public function extra_null_predicate_match_on_unique_key_query(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        // Load alice so her attributes (including deleted_at = null) are in the map
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // whereNull('deleted_at') is an extra predicate on top of the email unique key
        $result = User::where('email', 'alice@example.com')->whereNull('deleted_at')->first();

        $this->assertSame(0, $queryCount, 'IS NULL extra predicate evaluated in memory');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function extra_in_predicate_match_on_unique_key_query(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->whereIn('name', ['Alice', 'Bob'])->first();

        $this->assertSame(0, $queryCount, 'IN extra predicate evaluated in memory');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function extra_in_predicate_reject_on_unique_key_query(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Alice's name is 'Alice', not in ['Bob', 'Charlie']
        $result = User::where('email', 'alice@example.com')->whereIn('name', ['Bob', 'Charlie'])->first();

        $this->assertSame(0, $queryCount, 'IN reject uniquely eliminates the candidate — no SQL');
        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Absence NOT recorded when SQL returns a model (non-empty result)
    // -----------------------------------------------------------------------

    #[Test]
    public function absence_not_recorded_when_sql_returns_a_model(): void
    {
        $this->createFresh('Alice', 'alice@example.com');

        // First query: model not in map → SQL → returns alice
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result1 = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($result1);
        $this->assertSame(1, $queryCount);

        // Flush the map so alice is no longer cached
        $this->store->flush();
        $queryCount = 0;

        // Second query: must hit SQL again — absence should NOT have been recorded
        $result2 = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($result2);
        $this->assertSame(1, $queryCount, 'Absence must not be recorded when SQL returned a model');
    }

    // -----------------------------------------------------------------------
    // Hazard checks — joins and locks bypass unique-key shortcut
    // -----------------------------------------------------------------------

    #[Test]
    public function join_bypasses_unique_key_lookup(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::join('users as u2', 'users.id', '=', 'u2.id')
            ->where('users.email', 'alice@example.com')
            ->select('users.*')
            ->first();

        $this->assertSame(1, $queryCount, 'A query with a join must bypass the unique-key shortcut');
        $this->assertNotNull($result);
    }

    #[Test]
    public function lock_bypasses_unique_key_lookup(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->lockForUpdate()->first();

        $this->assertSame(1, $queryCount, 'A locking query must bypass the unique-key shortcut');
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------------
    // Safe-scope where BEFORE the equality — continue must not become break
    // -----------------------------------------------------------------------

    #[Test]
    public function safe_scope_where_before_equality_still_detected(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Force the deleted_at IS NULL where to appear before the email equality
        // by prepending it explicitly, simulating a scope applied before user wheres.
        $result = User::whereNull('users.deleted_at')
            ->where('email', 'alice@example.com')
            ->first();

        $this->assertSame(0, $queryCount, 'Safe-scope where before equality must not prevent unique-key detection');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    // -----------------------------------------------------------------------
    // Multiple configured indexes — second index used when first doesn't match
    // -----------------------------------------------------------------------

    #[Test]
    public function second_unique_index_used_when_first_does_not_match_query(): void
    {
        // Two indexes: ['email', 'name'] (compound) and ['email'] (single).
        // Query only has 'email', so compound index cannot match — single must be tried.
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [
                    ['email', 'name'],  // first: needs both columns
                    ['email'],          // second: needs only email
                ],
            ],
        ]]);

        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(0, $queryCount, 'Second index must be tried when first does not match');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    // -----------------------------------------------------------------------
    // Compound-key absence tracking — ksort must normalise column order
    // -----------------------------------------------------------------------

    #[Test]
    public function compound_key_absence_tracked_regardless_of_column_order(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [
                    ['name', 'email'],
                ],
            ],
        ]]);

        // First query: compound key lookup returns null
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $first = User::where('name', 'Ghost')->where('email', 'ghost@example.com')->first();
        $this->assertNull($first);
        $this->assertSame(1, $queryCount);

        $queryCount = 0;

        // Second query: same compound key but columns specified in the opposite order
        $second = User::where('email', 'ghost@example.com')->where('name', 'Ghost')->first();
        $this->assertNull($second);
        $this->assertSame(0, $queryCount, 'Compound-key absence must be found regardless of where-clause column order');
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

    // -----------------------------------------------------------------------
    // exists() — absence recorded after SQL miss
    // -----------------------------------------------------------------------

    #[Test]
    public function exists_records_absence_after_sql_miss(): void
    {
        // First exists() call hits SQL and returns false
        $first = User::where('email', 'nobody@example.com')->exists();
        $this->assertFalse($first);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Second call must be served from absence tracking
        $second = User::where('email', 'nobody@example.com')->exists();
        $this->assertFalse($second);
        $this->assertSame(0, $queryCount, 'exists() absence must be tracked after first SQL miss');
    }

    // -----------------------------------------------------------------------
    // offset / limit hazards
    // -----------------------------------------------------------------------

    #[Test]
    public function offset_bypasses_unique_key_lookup(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // OFFSET > 0 means the unique row may be skipped — must not serve from cache
        $result = User::where('email', 'alice@example.com')->offset(1)->first();
        $this->assertSame(1, $queryCount, 'offset > 0 must bypass unique-key shortcut');
        $this->assertNull($result);
    }

    #[Test]
    public function limit_zero_bypasses_unique_key_lookup(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // LIMIT 0 always returns empty — must not serve from cache
        $results = User::where('email', 'alice@example.com')->limit(0)->get();
        $this->assertSame(1, $queryCount, 'limit 0 must bypass unique-key shortcut');
        $this->assertCount(0, $results);
    }

    // -----------------------------------------------------------------------
    // value() — served from unique-key cache
    // -----------------------------------------------------------------------

    #[Test]
    public function value_served_from_unique_key_cache(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $email = User::where('email', 'alice@example.com')->value('email');
        $this->assertSame(0, $queryCount, 'value() must be served from unique-key cache');
        $this->assertSame('alice@example.com', $email);
    }

    // -----------------------------------------------------------------------
    // exists() — early-exit guards (identityMapDisabled, store disabled)
    // -----------------------------------------------------------------------

    #[Test]
    public function exists_bypassed_when_identity_map_disabled(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::withoutIdentityMap()->where('email', 'alice@example.com')->exists();
        $this->assertSame(1, $queryCount, 'withoutIdentityMap must bypass exists() shortcut');
        $this->assertTrue($result);
    }

    #[Test]
    public function exists_bypassed_when_store_disabled(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn() => User::where('email', 'alice@example.com')->exists());

        $this->assertSame(1, $queryCount, 'disabled scope must bypass exists() shortcut');
        $this->assertTrue($result);
    }

    // -----------------------------------------------------------------------
    // exists() — falls through when extractUniqueKeyLookup returns null
    // -----------------------------------------------------------------------

    #[Test]
    public function exists_falls_through_for_empty_where_list(): void
    {
        // No explicit where conditions → $query->wheres is empty → extractUniqueKeyLookup
        // returns null at the wheres === [] guard, exists() falls through to SQL.
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::exists();
        $this->assertSame(1, $queryCount, 'exists() with no wheres must fall through to SQL');
        $this->assertTrue($result);
    }

    #[Test]
    public function exists_falls_through_for_or_where_with_unique_config(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // orWhere produces boolean = 'or' — in exists() scopes haven't been applied yet,
        // so the raw wheres are visible and extractUniqueKeyLookup returns null at the
        // boolean !== 'and' guard, falling through to SQL.
        $result = User::where('email', 'alice@example.com')->orWhere('name', 'Alice')->exists();
        $this->assertSame(1, $queryCount, 'orWhere must bypass unique-key shortcut in exists()');
        $this->assertTrue($result);
    }

    #[Test]
    public function first_falls_through_for_duplicate_equality_constraint(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Two equality predicates on the same column → duplicate in equalityMap → null
        $result = User::where('email', 'alice@example.com')->where('email', 'alice@example.com')->first();
        $this->assertSame(1, $queryCount, 'Duplicate column constraint must bypass unique-key shortcut');
        $this->assertNotNull($result);
    }

    #[Test]
    public function first_falls_through_for_unsupported_operator_after_unique_key(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // LIKE is unsupported by PredicateExtractor → returns null → extractUniqueKeyLookup null
        $result = User::where('email', 'alice@example.com')->where('name', 'LIKE', '%Alice%')->first();
        $this->assertSame(1, $queryCount, 'Unsupported operator must bypass unique-key shortcut');
        $this->assertNotNull($result);
    }

    #[Test]
    public function first_falls_through_when_no_equality_predicates_match_unique_key(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Only a Null where — no equality predicates → equalityMap empty → extractUniqueKeyLookup null
        $result = User::whereNull('name')->first();
        $this->assertSame(1, $queryCount, 'No equality predicates must bypass unique-key shortcut');
        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // exists() — Unknown predicate falls through to SQL
    // -----------------------------------------------------------------------

    #[Test]
    public function exists_with_unknown_predicate_falls_through_to_sql(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        // Load with limited columns so 'active' is NOT in the cached entry's attributes
        User::select('id', 'email')->find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Email is a unique-key hit, but 'active' is an extra predicate on an
        // attribute not loaded → evaluates to Unknown → falls through to SQL
        $result = User::where('email', 'alice@example.com')->where('active', true)->exists();
        $this->assertSame(1, $queryCount, 'Unknown predicate must fall through to SQL');
        $this->assertTrue($result);
    }

    // -----------------------------------------------------------------------
    // IdentityMapStore — per-class flush clears unique index and absent maps
    // -----------------------------------------------------------------------

    #[Test]
    public function per_class_flush_clears_unique_index_and_absent_entries(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id); // populates uniqueIndex

        // Record a unique-key absence to populate uniqueAbsent
        User::where('email', 'nobody@example.com')->first();

        $statsBefore = $this->store->debugStats();
        $this->assertGreaterThan(0, $statsBefore['unique_index'], 'uniqueIndex must be populated before flush');
        $this->assertGreaterThan(0, $statsBefore['unique_absent'], 'uniqueAbsent must be populated before flush');

        IdentityMap::flush(User::class);

        $statsAfter = $this->store->debugStats();
        $this->assertSame(0, $statsAfter['unique_index'], 'Per-class flush must clear uniqueIndex');
        $this->assertSame(0, $statsAfter['unique_absent'], 'Per-class flush must clear uniqueAbsent');
    }

    // -----------------------------------------------------------------------
    // IdentityMapStore — stale uniqueIndex entry evicted when model is forgotten
    // -----------------------------------------------------------------------

    #[Test]
    public function forgotten_model_evicts_stale_unique_index_on_next_lookup(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        User::find($alice->id); // uniqueIndex now maps email fingerprint → mapKey

        // forget() removes the entry from entries but leaves uniqueIndex stale
        IdentityMap::forget($alice);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // findByUniqueKey finds the mapKey in uniqueIndex but entries[$mapKey] is null
        // → evicts the stale uniqueIndex entry → falls through to SQL
        $result = User::where('email', 'alice@example.com')->first();
        $this->assertSame(1, $queryCount, 'Stale uniqueIndex after forget must fall through to SQL');
        $this->assertNotNull($result);
    }

    // -----------------------------------------------------------------------
    // IdentityMapStore — malformed config entry is skipped gracefully
    // -----------------------------------------------------------------------

    #[Test]
    public function malformed_unique_config_entry_is_skipped_gracefully(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        // Provide a flat string instead of array-of-arrays — the non-array entry
        // must be skipped without error, yielding no unique indexes
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => ['email'],
            ],
        ]]);

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // No valid unique indexes → falls through to SQL
        $result = User::where('email', 'alice@example.com')->first();
        $this->assertSame(1, $queryCount, 'Malformed config must produce no unique indexes');
        $this->assertNotNull($result);
    }
}
