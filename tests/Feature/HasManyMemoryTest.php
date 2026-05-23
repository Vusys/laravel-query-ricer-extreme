<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class HasManyMemoryTest extends TestCase
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
    public function has_many_returns_full_collection_from_memory_after_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get();

        $this->assertSame(0, $queryCount, 'hasMany get() should hit memory after load');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function has_many_filters_in_memory_with_extra_predicate(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P3', 'published' => false]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $published = $user->posts()->where('published', true)->get();

        $this->assertSame(0, $queryCount, 'hasMany should filter in memory');
        $this->assertCount(2, $published);
    }

    #[Test]
    public function has_many_falls_back_to_sql_before_first_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany should issue SQL before first load');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_marks_complete_after_eager_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $userWithPosts = User::with('posts')->findOrFail($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $userWithPosts->posts()->get();

        $this->assertSame(0, $queryCount, 'hasMany get() should hit memory after eager load');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_falls_back_when_store_is_disabled(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn (): Collection => $user->posts()->get());

        $this->assertGreaterThan(0, $queryCount, 'hasMany should issue SQL when store disabled');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_marks_complete_after_lazy_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $loaded = $user->posts;

        $this->assertCount(2, $loaded);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get();

        $this->assertSame(0, $queryCount, 'hasMany get() should hit memory after lazy property load');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function has_many_falls_back_with_limit_applied(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => true]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->limit(1)->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany with limit should fall back to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_falls_back_when_entry_not_in_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post1 = Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $user->load('posts');
        $this->store->forget($post1);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->where('published', true)->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany should fall back when a child entry is missing from store');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_falls_back_with_non_star_columns(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get(['id', 'title']);

        $this->assertGreaterThan(0, $queryCount, 'hasMany with non-star columns should fall back to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_falls_back_with_unsupported_predicate(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'Post One', 'published' => true]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->where('title', 'LIKE', '%One%')->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany with LIKE predicate should fall back to SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_falls_back_when_store_disabled_on_lazy_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn (): Collection => $user->posts);

        $this->assertGreaterThan(0, $queryCount, 'hasMany getResults() should issue SQL when store disabled');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function has_many_does_not_mark_complete_after_constrained_eager_load(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $userWithPosts = User::with(['posts' => function (HasMany $q): void {
            $q->where('published', true);
        }])->findOrFail($user->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $userWithPosts->posts()->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany should issue SQL after constrained eager load');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function has_many_get_falls_back_when_query_has_join(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // A join is a hazard; queryHasHazards() must detect it and fall back to SQL.
        $user->posts()->join('tags', 'tags.id', '=', 'posts.tag_id')->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany get() with join must fall back to SQL');
    }

    #[Test]
    public function has_many_get_falls_back_when_parent_entry_flushed_from_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        // Load the relation on the model so relationLoaded() returns true inside get(),
        // then flush the store so findEntry() returns null at the null-safe call site.
        $user->load('posts');
        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get();

        $this->assertGreaterThan(0, $queryCount, 'hasMany get() must fall back to SQL when the parent entry is absent from the store');
        $this->assertCount(1, $result);
    }
}
