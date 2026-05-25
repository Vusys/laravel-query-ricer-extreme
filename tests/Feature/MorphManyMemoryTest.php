<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
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
    public function morph_many_includes_newly_created_child_after_initial_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'A']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'B']);

        $user->load('comments');
        $this->assertCount(2, $user->comments);

        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'C']);

        $result = $user->comments()->get();

        $this->assertCount(3, $result, 'newly created child must surface on the next morphMany query');
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

    #[Test]
    public function morph_many_marks_complete_after_lazy_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'world']);

        $loaded = $user->comments;

        $this->assertCount(2, $loaded);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->get();

        $this->assertSame(0, $queryCount, 'morphMany get() should hit memory after lazy property load');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function morph_many_falls_back_when_store_is_disabled(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn (): Collection => $user->comments()->get());

        $this->assertGreaterThan(0, $queryCount, 'morphMany should issue SQL when store disabled');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_falls_back_with_limit_applied(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'a']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'b']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->limit(1)->get();

        $this->assertGreaterThan(0, $queryCount, 'morphMany with limit should fall back to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_falls_back_when_entry_not_in_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment1 = Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'world']);

        $user->load('comments');
        $this->store->forget($comment1);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->where('body', 'hello')->get();

        $this->assertGreaterThan(0, $queryCount, 'morphMany should fall back when a child entry is missing from store');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_falls_back_with_non_star_columns(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->get(['id', 'body']);

        $this->assertGreaterThan(0, $queryCount, 'morphMany with non-star columns should fall back to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_falls_back_with_unsupported_predicate(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello world']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->where('body', 'LIKE', '%hello%')->get();

        $this->assertGreaterThan(0, $queryCount, 'morphMany with LIKE predicate should fall back to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_falls_back_when_store_disabled_on_lazy_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn (): Collection => $user->comments);

        $this->assertGreaterThan(0, $queryCount, 'morphMany getResults() should issue SQL when store disabled');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function morph_many_get_falls_back_when_query_has_join(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        $user->load('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // A join is a hazard; queryHasHazards() must detect it and fall back to SQL.
        $user->comments()->join('posts', 'posts.user_id', '=', 'comments.commentable_id')->get();

        $this->assertGreaterThan(0, $queryCount, 'morphMany get() with join must fall back to SQL');
    }

    #[Test]
    public function morph_many_get_falls_back_when_parent_entry_flushed_from_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'hello']);

        // Load the relation on the model so relationLoaded() returns true inside get(),
        // then flush the store so findEntry() returns null at the null-safe call site.
        $user->load('comments');
        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->get();

        $this->assertGreaterThan(0, $queryCount, 'morphMany get() must fall back to SQL when the parent entry is absent from the store');
        $this->assertCount(1, $result);
    }
}
