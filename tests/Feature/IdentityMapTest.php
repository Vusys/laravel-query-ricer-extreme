<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Vusys\QueryRicerExtreme\IdentityMapStore;
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

    public function test_service_provider_loads(): void
    {
        $this->assertNotNull($this->app);
        $this->assertInstanceOf(IdentityMapStore::class, resolve(IdentityMapStore::class));
    }

    public function test_find_returns_same_instance(): void
    {
        $userA = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $userB = User::find($userA->id);
        $userC = User::find($userA->id);

        $this->assertSame($userB, $userC);
    }

    public function test_find_returns_same_instance_as_created(): void
    {
        $created = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $found = User::find($created->id);

        $this->assertSame($created, $found);
    }

    public function test_second_find_issues_no_query(): void
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

    public function test_where_key_first_returns_same_instance(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        $fromWhereKey = User::whereKey($user->id)->first();

        $this->assertSame($user, $fromWhereKey);
    }

    public function test_where_key_first_issues_no_query_after_find(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);

        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::whereKey($user->id)->first();

        $this->assertSame(0, $queryCount, 'whereKey()->first() should not issue SQL when model is mapped');
    }

    public function test_find_returns_null_for_nonexistent_id(): void
    {
        $result = User::find(9999);

        $this->assertNull($result);
    }

    public function test_find_nonexistent_id_is_tracked_as_absent(): void
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

    public function test_flush_clears_all_entries(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $user = User::find(1);
        $this->assertInstanceOf(User::class, $user);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::find($user->id);

        $this->assertSame(1, $queryCount, 'Find after flush should issue SQL');
    }

    public function test_flush_for_model_class_only_clears_that_class(): void
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

    public function test_forget_removes_specific_model(): void
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

    public function test_without_identity_map_bypasses_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        User::withoutIdentityMap()->find($user->id);

        $this->assertSame(1, $queryCount, 'withoutIdentityMap() find should always issue SQL');
    }

    public function test_disabled_scope_bypasses_store(): void
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

    public function test_saved_model_updates_entry(): void
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

    public function test_soft_deleted_model_returns_null_from_default_scope(): void
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

    public function test_explain_returns_explanation_for_memory_hit(): void
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

    public function test_explain_returns_explanation_for_sql_execution(): void
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

    public function test_store_is_singleton(): void
    {
        $storeA = resolve(IdentityMapStore::class);
        $storeB = resolve(IdentityMapStore::class);

        $this->assertSame($storeA, $storeB);
    }
}
