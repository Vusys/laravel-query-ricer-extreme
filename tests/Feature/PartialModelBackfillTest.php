<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class PartialModelBackfillTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();

        config(['query-ricer-extreme.partial_models' => 'backfill_missing_columns']);
        config(['query-ricer-extreme.models' => [
            User::class => [
                'unique' => [
                    ['email'],
                ],
            ],
        ]]);
    }

    private function createUser(string $name = 'Alice', string $email = 'alice@example.com'): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'active' => true]);
        $this->store->flush();

        return $user;
    }

    // ---------------------------------------------------------------------
    // Default mode (query_normally) — backfill stays off; existing behavior
    // ---------------------------------------------------------------------

    #[Test]
    public function default_mode_falls_through_with_full_fetch(): void
    {
        config(['query-ricer-extreme.partial_models' => 'query_normally']);
        $alice = $this->createUser();

        User::select('id', 'name')->whereKey($alice->id)->first();

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $result = User::find($alice->id, ['id', 'email']);

        $this->assertCount(1, $queries, 'query_normally must issue a single full re-fetch');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('alice@example.com', $result->email);
    }

    // ---------------------------------------------------------------------
    // Narrow fetch fires when columns are missing
    // ---------------------------------------------------------------------

    #[Test]
    public function backfill_narrow_fetch_for_missing_columns_on_find(): void
    {
        $alice = $this->createUser();
        User::select('id', 'name')->whereKey($alice->id)->first();

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $result = User::find($alice->id, ['id', 'email']);

        $this->assertCount(1, $queries, 'Backfill must issue exactly one narrow query');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('alice@example.com', $result->email);
        $this->assertStringContainsString('email', $queries[0]);
        $this->assertStringNotContainsString('"bio"', $queries[0]);
        $this->assertStringNotContainsString('`bio`', $queries[0]);
    }

    #[Test]
    public function backfill_returns_same_instance(): void
    {
        $alice = $this->createUser();
        $loaded = User::select('id', 'name')->whereKey($alice->id)->first();

        $result = User::find($alice->id, ['id', 'email']);

        $this->assertSame($loaded, $result, 'Backfill must merge into the cached instance, not return a new one');
        $this->assertSame('alice@example.com', $result?->email);
    }

    #[Test]
    public function backfill_does_not_mark_all_columns_known(): void
    {
        $alice = $this->createUser();
        User::select('id', 'name')->whereKey($alice->id)->first();
        User::find($alice->id, ['id', 'email']);

        // Now request a column NOT yet fetched — should trigger another backfill.
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::find($alice->id, ['id', 'active']);

        $this->assertSame(1, $queries, 'allColumnsKnown must stay false after partial backfill');
    }

    // ---------------------------------------------------------------------
    // Subsequent point lookup of the now-known column is fully memory-served
    // ---------------------------------------------------------------------

    #[Test]
    public function repeat_lookup_after_backfill_uses_memory(): void
    {
        $alice = $this->createUser();
        User::select('id', 'name')->whereKey($alice->id)->first();
        User::find($alice->id, ['id', 'email']);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = User::find($alice->id, ['id', 'email']);

        $this->assertSame(0, $queries, 'Repeat lookup of backfilled columns must skip SQL');
        $this->assertInstanceOf(User::class, $result);
        $this->assertSame('alice@example.com', $result->email);
    }

    // ---------------------------------------------------------------------
    // Dirty preservation
    // ---------------------------------------------------------------------

    #[Test]
    public function backfill_preserves_dirty_attribute_set_before_backfill(): void
    {
        $alice = $this->createUser();
        $loaded = User::select('id', 'name')->whereKey($alice->id)->first();
        $this->assertNotNull($loaded);
        $loaded->name = 'Locally Changed';

        // Backfill on email should NOT touch the dirty name.
        User::find($alice->id, ['id', 'email']);

        $this->assertSame('Locally Changed', $loaded->name);
        $this->assertSame('alice@example.com', $loaded->email);
        $this->assertTrue($loaded->isDirty('name'));
        $this->assertFalse($loaded->isDirty('email'));
    }

    #[Test]
    public function backfill_preserves_dirty_column_when_dirty_column_is_the_backfill_target(): void
    {
        $alice = $this->createUser();
        $loaded = User::select('id', 'name')->whereKey($alice->id)->first();
        $this->assertNotNull($loaded);
        $loaded->email = 'dirty@example.com';

        // Now request email — column exists on the model as a dirty value but is
        // not yet tracked in AttributeKnowledge. Backfill should keep the dirty
        // value and only record the DB value as the new original.
        User::find($alice->id, ['id', 'email']);

        $this->assertSame('dirty@example.com', $loaded->email);
        $this->assertTrue($loaded->isDirty('email'));
        $this->assertSame('alice@example.com', $loaded->getRawOriginal('email'));
    }

    // ---------------------------------------------------------------------
    // unique-key entry path triggers backfill too
    // ---------------------------------------------------------------------

    #[Test]
    public function unique_key_partial_entry_triggers_backfill(): void
    {
        $alice = $this->createUser();
        User::select('id', 'name', 'email')->whereKey($alice->id)->first();

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        // 'active' is unknown on the partial entry but the unique-key index has it.
        $result = User::where('email', 'alice@example.com')->first(['id', 'email', 'active']);

        $this->assertCount(1, $queries, 'Backfill must issue exactly one narrow query');
        $this->assertNotNull($result);
        $this->assertTrue($result->active);
        $this->assertStringContainsString('active', $queries[0]);
    }

    // ---------------------------------------------------------------------
    // MemoryBelongsTo path triggers backfill
    // ---------------------------------------------------------------------

    #[Test]
    public function belongs_to_partial_parent_triggers_backfill(): void
    {
        $alice = $this->createUser();
        $post = Post::create(['user_id' => $alice->id, 'title' => 'Hello']);
        $this->store->flush();

        User::select('id', 'name')->whereKey($alice->id)->first();
        $post = Post::find($post->id);
        $this->assertInstanceOf(Post::class, $post);

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        // Request a specific column on the parent that isn't yet known.
        $user = $post->user()->select('id', 'email')->getResults();

        $this->assertCount(1, $queries, 'BelongsTo backfill must issue exactly one narrow query');
        $this->assertNotNull($user);
        $this->assertSame('alice@example.com', $user->email);
    }

    // ---------------------------------------------------------------------
    // Race: row deleted out from under us → fall through cleanly
    // ---------------------------------------------------------------------

    #[Test]
    public function backfill_falls_through_when_row_disappears(): void
    {
        $alice = $this->createUser();
        $loaded = User::select('id', 'name')->whereKey($alice->id)->first();
        $this->assertNotNull($loaded);

        // Force-delete out from under the cache, bypassing identity-map writes.
        DB::table('users')->where('id', $alice->id)->delete();

        $result = User::find($alice->id, ['id', 'email']);

        $this->assertNull($result, 'Backfill must fall through to SQL when row has disappeared');
    }

    // ---------------------------------------------------------------------
    // Explanations
    // ---------------------------------------------------------------------

    #[Test]
    public function backfill_captures_expected_explanations(): void
    {
        $alice = $this->createUser();
        User::select('id', 'name')->whereKey($alice->id)->first();

        $explanations = IdentityMap::explain(function () use ($alice): void {
            User::find($alice->id, ['id', 'email']);
        });

        $types = array_map(static fn (Explanation $e): PlanType => $e->type, $explanations);
        $this->assertContains(PlanType::BackfillColumnsFromDatabase, $types);
        $this->assertContains(PlanType::ReturnModelFromMemory, $types);

        $backfill = array_values(array_filter(
            $explanations,
            static fn (Explanation $e): bool => $e->type === PlanType::BackfillColumnsFromDatabase,
        ))[0];
        $this->assertTrue($backfill->sqlExecuted);
        $this->assertSame(['email'], $backfill->missingKeys);

        $served = array_values(array_filter(
            $explanations,
            static fn (Explanation $e): bool => $e->type === PlanType::ReturnModelFromMemory,
        ))[0];
        $this->assertFalse($served->sqlExecuted);
        $this->assertSame('exact-primary-key-hit-after-backfill', $served->reason);
    }

    // ---------------------------------------------------------------------
    // '*' columns never backfill — caller is asking for everything
    // ---------------------------------------------------------------------

    #[Test]
    public function star_request_falls_through_to_full_fetch(): void
    {
        $alice = $this->createUser();
        User::select('id', 'name')->whereKey($alice->id)->first();

        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $result = User::find($alice->id);

        $this->assertCount(1, $queries);
        $this->assertNotNull($result);
        $sql = $queries[0];
        $this->assertMatchesRegularExpression('/select\s+\*/i', $sql);
    }
}
