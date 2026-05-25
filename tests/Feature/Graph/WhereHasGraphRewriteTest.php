<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Graph;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class WhereHasGraphRewriteTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    /** @var array<int, string> */
    private array $observedSql = [];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    /** @return array{0:User,1:User,2:array<int,Post>,3:array<int,Post>} */
    private function seedUsersWithPosts(): array
    {
        // All User creates first, then Post creates — creating a model invalidates
        // graph entries for that class, so we batch creates by class.
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        $alicePosts = [
            Post::create(['user_id' => $alice->id, 'title' => 'A1', 'published' => true]),
            Post::create(['user_id' => $alice->id, 'title' => 'A2', 'published' => false]),
        ];
        $bobPosts = [
            Post::create(['user_id' => $bob->id, 'title' => 'B1', 'published' => false]),
        ];

        $alice->load('posts');
        $bob->load('posts');

        return [$alice, $bob, $alicePosts, $bobPosts];
    }

    private function startSqlListener(): void
    {
        $this->observedSql = [];
        DB::listen(function ($q): void {
            $this->observedSql[] = $q->sql;
        });
    }

    private function assertNoSql(): void
    {
        $this->assertSame([], $this->observedSql, 'expected zero SQL; got: '.implode(' | ', $this->observedSql));
    }

    private function assertSomeSql(): void
    {
        $this->assertNotSame([], $this->observedSql, 'expected SQL fallthrough but none was issued');
    }

    #[Test]
    public function bounded_outer_with_inner_equality_serves_from_memory(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereHas('posts', fn ($q) => $q->where('published', true))
            ->get();

        $this->assertNoSql();
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function bounded_outer_with_inner_equality_explains_as_where_has_from_graph(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        $explanations = $this->store->explain(function () use ($alice, $bob): void {
            User::whereKey([$alice->id, $bob->id])
                ->whereHas('posts', fn ($q) => $q->where('published', true))
                ->get();
        });

        $types = array_map(static fn (Explanation $e): PlanType => $e->type, $explanations);
        $this->assertContains(PlanType::WhereHasFromGraph, $types);
    }

    #[Test]
    public function bare_where_has_existence_is_resolved_from_graph(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $charlie = User::create(['name' => 'Charlie', 'email' => 'charlie@example.com']);

        Post::create(['user_id' => $alice->id, 'title' => 'A1', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'B1', 'published' => false]);

        $alice->load('posts');
        $bob->load('posts');
        $charlie->load('posts');

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id, $charlie->id])
            ->whereHas('posts')
            ->get();

        $this->assertNoSql();
        $ids = $result->pluck('id')->all();
        $this->assertContains($alice->id, $ids);
        $this->assertContains($bob->id, $ids);
        $this->assertNotContains($charlie->id, $ids, 'charlie has no posts → excluded');
    }

    #[Test]
    public function where_doesnt_have_inverts_membership(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereDoesntHave('posts', fn ($q) => $q->where('published', true))
            ->get();

        $this->assertNoSql();
        $this->assertCount(1, $result);
        $this->assertSame($bob->id, $result->first()?->id, 'bob has no published posts');
    }

    #[Test]
    public function where_doesnt_have_explains_as_dedicated_plan_type(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        $explanations = $this->store->explain(function () use ($alice, $bob): void {
            User::whereKey([$alice->id, $bob->id])
                ->whereDoesntHave('posts', fn ($q) => $q->where('published', true))
                ->get();
        });

        $types = array_map(static fn (Explanation $e): PlanType => $e->type, $explanations);
        $this->assertContains(PlanType::WhereDoesntHaveFromGraph, $types);
    }

    #[Test]
    public function belongs_to_where_has_with_inner_predicate_serves_from_memory(): void
    {
        // Attributes must be set explicitly — DB defaults aren't reflected in the
        // model after create, so the in-memory evaluator wouldn't know `active`.
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        $tag = Tag::create(['name' => 'php']);

        $alicePost = Post::create(['user_id' => $alice->id, 'tag_id' => $tag->id, 'title' => 'A1', 'published' => true]);
        $bobPost = Post::create(['user_id' => $bob->id, 'tag_id' => $tag->id, 'title' => 'B1', 'published' => true]);

        $this->startSqlListener();
        $result = Post::whereKey([$alicePost->id, $bobPost->id])
            ->whereHas('user', fn ($q) => $q->where('active', true))
            ->get();

        $this->assertNoSql();
        $this->assertCount(1, $result);
        $this->assertSame($alicePost->id, $result->first()?->id);
    }

    #[Test]
    public function partial_coverage_falls_through_to_sql(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        $dave = User::create(['name' => 'Dave', 'email' => 'dave@example.com']);
        Post::create(['user_id' => $dave->id, 'title' => 'D1', 'published' => true]);
        // Dave's posts are never loaded → no graph coverage for him → Unknown forces SQL.

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id, $dave->id])
            ->whereHas('posts', fn ($q) => $q->where('published', true))
            ->get();

        $this->assertSomeSql();
        $ids = $result->pluck('id')->all();
        $this->assertContains($alice->id, $ids);
        $this->assertContains($dave->id, $ids);
        $this->assertNotContains($bob->id, $ids);
    }

    #[Test]
    public function evicted_child_forces_sql_fallthrough(): void
    {
        [$alice, $bob, $alicePosts] = $this->seedUsersWithPosts();

        // Coverage references a child key that no longer maps to a store entry.
        $this->store->forget($alicePosts[0]);

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereHas('posts', fn ($q) => $q->where('published', true))
            ->get();

        $this->assertSomeSql();
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function unsupported_inner_predicate_falls_through(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        // whereRaw isn't extractable as a PredicateNode → no rewrite recorded → SQL.
        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereHas('posts', fn ($q) => $q->whereRaw('published = ?', [1]))
            ->get();

        $this->assertSomeSql();
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function nested_relation_string_falls_through(): void
    {
        [$alice, $bob] = $this->seedUsersWithPosts();

        // Nested 'posts.tag' is explicitly out of scope for this milestone.
        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereHas('posts.tag', fn ($q) => $q->where('name', 'php'))
            ->get();

        $this->assertSomeSql();
        $this->assertCount(0, $result);
    }

    #[Test]
    public function morph_many_where_has_serves_from_graph(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        Comment::create(['commentable_type' => User::class, 'commentable_id' => $alice->id, 'body' => 'hi', 'likes' => 5]);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $bob->id, 'body' => 'meh', 'likes' => 0]);

        $alice->load('comments');
        $bob->load('comments');

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->whereHas('comments', fn ($q) => $q->where('likes', '>', 0))
            ->get();

        $this->assertNoSql();
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function complex_outer_query_still_uses_graph_for_existence(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'A1', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'B1', 'published' => false]);
        $alice->load('posts');
        $bob->load('posts');

        $this->startSqlListener();
        $result = User::whereKey([$alice->id, $bob->id])
            ->where('active', true)
            ->whereHas('posts', fn ($q) => $q->where('published', true))
            ->get();

        $this->assertNoSql();
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function belongs_to_with_missing_parent_in_store_falls_through(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $tag = Tag::create(['name' => 'php']);
        $post = Post::create(['user_id' => $alice->id, 'tag_id' => $tag->id, 'title' => 'A1', 'published' => true]);

        // Evict the parent so the BelongsTo lookup misses → Unknown → SQL fallback.
        $this->store->forget($alice);

        $this->startSqlListener();
        $result = Post::whereKey([$post->id])
            ->whereHas('user', fn ($q) => $q->where('active', true))
            ->get();

        $this->assertSomeSql();
        $this->assertCount(1, $result);
    }

    #[Test]
    public function belongs_to_with_null_fk_rejects_without_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $tag = Tag::create(['name' => 'php']);
        $taggedPost = Post::create(['user_id' => $alice->id, 'tag_id' => $tag->id, 'title' => 'tagged', 'published' => true]);
        $untaggedPost = Post::create(['user_id' => $alice->id, 'tag_id' => null, 'title' => 'untagged', 'published' => true]);

        $this->startSqlListener();
        $result = Post::whereKey([$taggedPost->id, $untaggedPost->id])
            ->whereHas('tag', fn ($q) => $q->where('name', 'php'))
            ->get();

        $this->assertNoSql();
        $this->assertCount(1, $result);
        $this->assertSame($taggedPost->id, $result->first()?->id);
    }

    #[Test]
    public function explanation_reason_distinguishes_memory_only_vs_partial_rewrite(): void
    {
        // All Users + all Posts first, then loads — otherwise creating dave
        // invalidates alice/bob's coverage (M13 conservatism).
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        $dave = User::create(['name' => 'Dave', 'email' => 'dave@example.com']);

        Post::create(['user_id' => $alice->id, 'title' => 'A1', 'published' => true]);
        Post::create(['user_id' => $bob->id, 'title' => 'B1', 'published' => false]);
        Post::create(['user_id' => $dave->id, 'title' => 'D1', 'published' => true]);

        $alice->load('posts');
        $bob->load('posts');
        // dave's posts intentionally not loaded → no coverage for dave → forces SQL.

        $memOnly = $this->store->explain(function () use ($alice, $bob): void {
            User::whereKey([$alice->id, $bob->id])
                ->whereHas('posts', fn ($q) => $q->where('published', true))
                ->get();
        });

        $partial = $this->store->explain(function () use ($alice, $bob, $dave): void {
            User::whereKey([$alice->id, $bob->id, $dave->id])
                ->whereHas('posts', fn ($q) => $q->where('published', true))
                ->get();
        });

        $memReasons = array_map(static fn (Explanation $e): string => $e->reason, $memOnly);
        $partialReasons = array_map(static fn (Explanation $e): string => $e->reason, $partial);

        $this->assertContains('has-rewrite-pruned-all-known', $memReasons);
        $this->assertContains('has-rewrite-prune-and-rewrite', $partialReasons);
    }
}
