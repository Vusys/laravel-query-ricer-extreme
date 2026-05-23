<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class MorphManyMemoryTest extends TestCase
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
    public function morph_many_returns_full_collection_from_memory_after_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'world']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->get();

        $this->assertSame(0, $queryCount, 'morphMany get() should hit memory after load');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function morph_many_filters_in_memory_with_extra_predicate(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'world']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $hellos = $user->comments()->where('body', 'hello')->get();

        $this->assertSame(0, $queryCount, 'morphMany should filter in memory');
        $this->assertCount(2, $hellos);
    }

    #[Test]
    public function morph_many_falls_back_to_sql_before_first_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->get();

        $this->assertGreaterThan(0, $queryCount, 'morphMany should issue SQL before first load');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_marks_complete_after_eager_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        $userWithComments = User::with('comments')->findOrFail($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $userWithComments->comments()->get();

        $this->assertSame(0, $queryCount, 'morphMany get() should hit memory after eager load');
        $this->assertCount(1, $result);
    }
}
