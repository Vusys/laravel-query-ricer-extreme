<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Label;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class BelongsToMemoryTest extends TestCase
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
    public function belongs_to_returns_related_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user;

        $this->assertSame(0, $queryCount, 'belongsTo should hit memory without SQL');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_falls_back_to_sql_when_related_not_in_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user;

        $this->assertGreaterThan(0, $queryCount, 'belongsTo should issue SQL when not in memory');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_returns_same_instance_as_cached(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $result = $post->user;

        $this->assertSame($user, $result);
    }

    #[Test]
    public function belongs_to_falls_back_when_store_is_disabled(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn () => $post->user);

        $this->assertGreaterThan(0, $queryCount, 'belongsTo should issue SQL when store disabled');
        $this->assertNotNull($result);
    }

    #[Test]
    public function belongs_to_returns_null_when_fk_is_null(): void
    {
        $post = new Post(['title' => 'Draft', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user;

        $this->assertSame(0, $queryCount, 'belongsTo should not issue SQL when FK is null');
        $this->assertNull($result);
    }

    #[Test]
    public function belongs_to_falls_back_when_related_has_no_identity_map(): void
    {
        $label = Label::create(['name' => 'php']);
        $post = Post::create(['user_id' => User::create(['name' => 'Alice', 'email' => 'a@example.com'])->id, 'label_id' => $label->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->label;

        $this->assertGreaterThan(0, $queryCount, 'belongsTo should issue SQL when related model has no HasIdentityMap');
        $this->assertNotNull($result);
        $this->assertInstanceOf(Label::class, $result);
    }

    #[Test]
    public function belongs_to_falls_back_when_related_without_trait_is_in_store(): void
    {
        // Label lacks HasIdentityMap. Manually putting it in the store must not cause the
        // belongsTo to serve it from memory — the !in_array(HasIdentityMap) guard must fire.
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $label = Label::create(['name' => 'php']);
        $post = Post::create(['user_id' => $user->id, 'label_id' => $label->id, 'title' => 'Hello', 'published' => false]);

        $this->store->remember($label);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->label;

        $this->assertGreaterThan(0, $queryCount, 'belongsTo must fall back to SQL when related model lacks HasIdentityMap, even if the entry is in the store');
        $this->assertInstanceOf(Label::class, $result);
    }

    #[Test]
    public function belongs_to_falls_back_when_query_has_join(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $relation = $post->user();
        $relation->getQuery()->join('tags', 'tags.id', '=', 'users.id');

        $explanations = $this->store->explain(fn () => $relation->getResults());

        $planTypes = array_map(fn (Explanation $e) => $e->type->value, $explanations);
        $this->assertNotContains(
            'return_belongs_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryBelongsTo from serving directly when a join is present',
        );
    }

    #[Test]
    public function belongs_to_falls_back_when_query_has_group_by(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $relation = $post->user();
        $relation->getQuery()->groupBy('users.id');

        // MariaDB (ONLY_FULL_GROUP_BY) rejects SELECT * GROUP BY id; use explain() to verify
        // the decision path regardless of whether the SQL itself succeeds on the current engine.
        try {
            $explanations = $this->store->explain(fn () => $relation->getResults());
        } catch (QueryException) {
            $explanations = [];
        }

        $planTypes = array_map(fn (Explanation $e) => $e->type->value, $explanations);
        $this->assertNotContains(
            'return_belongs_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryBelongsTo from serving directly when GROUP BY is present',
        );
    }

    #[Test]
    public function belongs_to_falls_back_when_query_has_having(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $relation = $post->user();
        // Add HAVING directly to the base Query\Builder so queryHasHazards() can see it
        // (Eloquent\Builder::havingRaw() routes through toBase()/applyScopes() which
        // clones the builder, so the HAVING would not be visible to the relation's query).
        $relation->getQuery()->getQuery()->havingRaw('1 = 1');

        // DB::listen only fires for successful queries; SQLite rejects HAVING without GROUP BY.
        // Use explain() to verify the decision path instead — it works regardless of SQL outcome.
        try {
            $explanations = $this->store->explain(fn () => $relation->getResults());
        } catch (QueryException) {
            // SQLite rejects HAVING without GROUP BY; the important thing is we attempted SQL
            $explanations = [];
        }

        $planTypes = array_map(fn (Explanation $e) => $e->type->value, $explanations);
        $this->assertNotContains(
            'return_belongs_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryBelongsTo from serving directly when HAVING is present',
        );
    }

    #[Test]
    public function belongs_to_falls_back_when_query_has_lock(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $relation = $post->user();
        $relation->getQuery()->lockForUpdate();
        $relation->getResults();

        $this->assertGreaterThan(0, $queryCount, 'queryHasHazards() must fall back to SQL when a row lock is present');
    }

    #[Test]
    public function belongs_to_falls_back_when_extra_where_constraint_present(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // An extra where beyond the FK constraint means hasOnlyBaseConstraints() returns
        // false, which must trigger a SQL fallback.
        $result = $post->user()->where('name', 'Alice')->getResults();

        $this->assertGreaterThan(0, $queryCount, 'belongsTo with an extra where must fall back to SQL');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_serves_from_memory_when_soft_delete_null_where_added(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user()->whereNull('users.deleted_at')->getResults();

        $this->assertSame(0, $queryCount, 'isSafeGlobalScopeWhere must accept WHERE users.deleted_at IS NULL so memory still serves the relation');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_falls_back_when_soft_delete_null_followed_by_unrelated_where(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user()->whereNull('users.deleted_at')->where('name', 'Alice')->getResults();

        $this->assertGreaterThan(
            0,
            $queryCount,
            'a safe soft-delete where must `continue` rather than `break` so the trailing extra where still forces fallback',
        );
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_falls_back_when_non_null_where_targets_deleted_at_column(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $post->user()->where('users.deleted_at', '>', '2026-01-01')->getResults();

        $this->assertGreaterThan(
            0,
            $queryCount,
            'isSafeGlobalScopeWhere must reject a Basic where on deleted_at — only WHERE IS NULL is safe; otherwise memory could serve a stale row',
        );
    }

    #[Test]
    public function belongs_to_with_specific_select_columns_served_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // Explicit column list: exercises the non-null rawCols branch in MemoryBelongsTo
        $result = $post->user()->select(['id', 'name', 'email'])->getResults();

        $this->assertSame(0, $queryCount, 'belongsTo with explicit columns must serve from memory when those columns are cached');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_falls_back_when_select_contains_raw_expression(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // A raw expression in SELECT cannot be served from the attribute cache
        $result = $post->user()->selectRaw('id, name')->getResults();

        $this->assertGreaterThan(0, $queryCount, 'belongsTo with a raw SELECT expression must fall back to SQL');
        $this->assertNotNull($result);
    }

    #[Test]
    public function belongs_to_cache_hit_records_belongs_to_memory_hit_reason(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $explanations = $this->store->explain(fn () => $post->user()->getResults());

        $reasons = array_map(fn (Explanation $e): string => $e->reason, $explanations);

        $this->assertContains(
            'belongs-to-memory-hit',
            $reasons,
            'belongsTo cache hit (no backfill) must capture an explanation with reason "belongs-to-memory-hit"',
        );
        $this->assertNotContains(
            'belongs-to-memory-hit-after-backfill',
            $reasons,
            'a vanilla cache hit must not be reported as a post-backfill hit',
        );
    }

    #[Test]
    public function belongs_to_falls_back_when_cached_entry_is_soft_deleted(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        // Soft-delete the parent: the entry's LifecycleState flips to SoftDeleted
        // under the same default-scope fingerprint the relation lookup uses.
        $user->delete();

        $explanations = $this->store->explain(fn () => $post->user()->getResults());

        $reasons = array_map(fn (Explanation $e): string => $e->reason, $explanations);

        $this->assertNotContains(
            'belongs-to-memory-hit',
            $reasons,
            'a cached entry in a non-Exists lifecycle state must not be served from memory under the default scope',
        );
        $this->assertNotContains(
            'belongs-to-memory-hit-after-backfill',
            $reasons,
            'a cached entry in a non-Exists lifecycle state must not be served via backfill either',
        );
    }
}
