<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Label;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class MorphToMemoryTest extends TestCase
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
    public function morph_to_returns_related_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $explanations = $this->store->explain(function () use ($comment): void {
            $comment->commentable()->getResults();
        });

        $result = $comment->commentable;

        $this->assertSame(0, $queryCount, 'morphTo should hit memory without SQL');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->getKey());

        $planTypes = array_map(fn (Explanation $e): string => $e->type->value, $explanations);
        $this->assertContains('return_morph_to_from_memory', $planTypes, 'morphTo memory hit must capture its own plan type');
    }

    #[Test]
    public function morph_to_returns_same_instance_as_cached(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $result = $comment->commentable;

        $this->assertSame($user, $result);
    }

    #[Test]
    public function morph_to_falls_back_to_sql_when_related_not_in_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertGreaterThan(0, $queryCount, 'morphTo should issue SQL when not in memory');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->getKey());
    }

    #[Test]
    public function morph_to_falls_back_when_store_is_disabled(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn () => $comment->commentable);

        $this->assertGreaterThan(0, $queryCount, 'morphTo should issue SQL when store disabled');
        $this->assertNotNull($result);
    }

    #[Test]
    public function morph_to_returns_null_when_morph_type_is_null(): void
    {
        $comment = new Comment(['commentable_id' => 1, 'body' => 'test']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertSame(0, $queryCount, 'morphTo should not issue SQL when morph type is null');
        $this->assertNull($result);
    }

    #[Test]
    public function morph_to_returns_null_when_fk_is_null(): void
    {
        $comment = new Comment(['commentable_type' => User::class, 'body' => 'test']);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertSame(0, $queryCount, 'morphTo should not issue SQL when FK is null');
        $this->assertNull($result);
    }

    #[Test]
    public function morph_to_falls_back_when_related_has_no_identity_map(): void
    {
        $label = Label::create(['name' => 'php']);
        $comment = Comment::create([
            'commentable_type' => Label::class,
            'commentable_id' => $label->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertGreaterThan(0, $queryCount, 'morphTo should issue SQL when related model has no HasIdentityMap');
        $this->assertNotNull($result);
        $this->assertInstanceOf(Label::class, $result);
    }

    #[Test]
    public function morph_to_falls_back_when_related_without_trait_is_in_store(): void
    {
        // Label lacks HasIdentityMap. Manually storing it must not cause morphTo to serve it
        // from memory — the !in_array(HasIdentityMap) guard must fire.
        $label = Label::create(['name' => 'php']);
        $comment = Comment::create([
            'commentable_type' => Label::class,
            'commentable_id' => $label->id,
            'body' => 'hello',
        ]);

        $this->store->remember($label);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertGreaterThan(0, $queryCount, 'morphTo must fall back to SQL when related model lacks HasIdentityMap, even if the entry is in the store');
        $this->assertInstanceOf(Label::class, $result);
    }

    #[Test]
    public function morph_to_falls_back_when_query_has_join(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $relation = $comment->commentable();
        $relation->getQuery()->join('tags', 'tags.id', '=', 'users.id');

        $explanations = $this->store->explain(fn () => $relation->getResults());

        $planTypes = array_map(fn (Explanation $e) => $e->type->value, $explanations);
        $this->assertNotContains(
            'return_morph_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryMorphTo from serving directly when a join is present',
        );
    }

    #[Test]
    public function morph_to_falls_back_when_query_has_group_by(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $relation = $comment->commentable();
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
            'return_morph_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryMorphTo from serving directly when GROUP BY is present',
        );
    }

    #[Test]
    public function morph_to_falls_back_when_query_has_having(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $relation = $comment->commentable();
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
            'return_morph_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryMorphTo from serving directly when HAVING is present',
        );
    }

    #[Test]
    public function morph_to_falls_back_when_query_has_lock(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $relation = $comment->commentable();
        $relation->getQuery()->lockForUpdate();
        $relation->getResults();

        $this->assertGreaterThan(0, $queryCount, 'queryHasHazards() must fall back to SQL when a row lock is present');
    }

    #[Test]
    public function morph_to_serves_from_memory_when_soft_delete_null_where_added(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable()->whereNull('users.deleted_at')->getResults();

        $this->assertSame(0, $queryCount, 'isSafeGlobalScopeWhere must accept the WHERE deleted_at IS NULL added by SoftDeletes so memory still serves the morph');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->getKey());
    }

    #[Test]
    public function morph_to_falls_back_when_soft_delete_null_followed_by_unrelated_where(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $relation = $comment->commentable()->whereNull('users.deleted_at')->where('name', 'Alice');
        $relation->getResults();

        $this->assertGreaterThan(
            0,
            $queryCount,
            'a safe soft-delete where must `continue` rather than `break` so the trailing extra where still forces fallback',
        );
    }

    #[Test]
    public function morph_to_falls_back_when_non_null_where_targets_deleted_at_column(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $comment->commentable()->where('users.deleted_at', '>', '2026-01-01')->getResults();

        $this->assertGreaterThan(
            0,
            $queryCount,
            'isSafeGlobalScopeWhere must reject a Basic where on deleted_at — only WHERE IS NULL is safe; otherwise memory could serve a stale row',
        );
    }
}
