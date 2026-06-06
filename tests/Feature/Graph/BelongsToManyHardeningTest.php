<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Graph;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\EdgeConfidence;
use Vusys\QueryRicerExtreme\Graph\EdgeSource;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Hardening for MemoryBelongsToMany: drives hazard branches, all pivot
 * predicate operators, and edge-population edge cases. Targets escaped mutants
 * surfaced by infection.
 */
final class BelongsToManyHardeningTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    private int $seq = 0;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    private function makePostWithTags(int $tagCount): Post
    {
        $this->seq++;
        $user = User::create(['name' => "U{$this->seq}", 'email' => "u{$this->seq}@example.com"]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);

        for ($i = 1; $i <= $tagCount; $i++) {
            $tag = Tag::create(['name' => "t{$this->seq}-{$i}", 'priority' => $i]);
            $post->tags()->attach($tag, ['active' => $i % 2 === 1, 'priority' => $i * 10]);
        }

        return $post;
    }

    private function countQueries(callable $cb): int
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });
        $cb();

        return $n;
    }

    // ------------------------------------------------------------------
    // queryHasHazards — each hazard branch must force SQL fallback
    // ------------------------------------------------------------------

    #[Test]
    public function limit_clause_is_a_hazard_and_falls_back_to_sql(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get(); // populates coverage

        $n = $this->countQueries(function () use ($post): void {
            $post->tags()->limit(1)->get();
        });

        $this->assertGreaterThan(0, $n, 'limit must be a hazard — SQL required');
    }

    #[Test]
    public function offset_clause_is_a_hazard_and_falls_back_to_sql(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $post->tags()->limit(10)->offset(1)->get();
        });

        $this->assertGreaterThan(0, $n, 'offset > 0 must be a hazard');
    }

    #[Test]
    public function group_by_is_a_hazard_and_falls_back_to_sql(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();

        // The query may error on strict-mode backends (ONLY_FULL_GROUP_BY); we only
        // care that SQL was attempted, which proves the memory path was bypassed.
        // On strict backends DB::listen does not fire for failed queries, so the
        // thrown QueryException is the alternative signal.
        $sqlAttempted = $this->sqlWasAttempted(function () use ($post): void {
            $post->tags()->groupBy('tags.id')->get();
        });

        $this->assertTrue($sqlAttempted, 'group by must be a hazard');
    }

    #[Test]
    public function having_is_a_hazard_and_falls_back_to_sql(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();

        $sqlAttempted = $this->sqlWasAttempted(function () use ($post): void {
            $post->tags()->groupBy('tags.id')->having('tags.id', '>', 0)->get();
        });

        $this->assertTrue($sqlAttempted, 'having must be a hazard');
    }

    private function sqlWasAttempted(callable $cb): bool
    {
        $n = 0;
        DB::listen(function () use (&$n): void {
            $n++;
        });

        try {
            $cb();
        } catch (QueryException) {
            return true;
        }

        return $n > 0;
    }

    #[Test]
    public function lock_is_a_hazard_and_falls_back_to_sql(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('sharedLock not supported on sqlite');
        }

        $post = $this->makePostWithTags(2);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $post->tags()->sharedLock()->get();
        });

        $this->assertGreaterThan(0, $n);
    }

    #[Test]
    public function extra_join_is_a_hazard_and_falls_back_to_sql(): void
    {
        $post = $this->makePostWithTags(2);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $post->tags()
                ->join('users', 'users.id', '=', 'tags.id')
                ->get();
        });

        $this->assertGreaterThan(0, $n, 'extra join beyond the pivot join must be a hazard');
    }

    // ------------------------------------------------------------------
    // wherePivot operator coverage
    // ------------------------------------------------------------------

    #[Test]
    public function where_pivot_equality_falses_count_correctly(): void
    {
        $post = $this->makePostWithTags(4); // 2 active, 2 inactive
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $inactive = $post->tags()->wherePivot('active', false)->get();
            $this->assertCount(2, $inactive);
        });

        $this->assertSame(0, $n);
    }

    #[Test]
    public function where_pivot_not_equal_resolves_in_memory(): void
    {
        $post = $this->makePostWithTags(4);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $not3 = $post->tags()->wherePivot('priority', '!=', 30)->get();
            $this->assertCount(3, $not3);
        });

        $this->assertSame(0, $n);
    }

    #[Test]
    public function where_pivot_lt_le_gt_ge_all_resolve_in_memory(): void
    {
        $post = $this->makePostWithTags(4); // priorities 10..40

        // Force load so coverage is established BEFORE the various filters.
        $post->tags()->get();

        $cases = [
            ['<', 25, 2],
            ['<=', 30, 3],
            ['>', 25, 2],
            ['>=', 30, 2],
        ];

        foreach ($cases as [$op, $val, $expectedCount]) {
            $n = $this->countQueries(function () use ($post, $op, $val, $expectedCount): void {
                $rs = $post->tags()->wherePivot('priority', $op, $val)->get();
                $this->assertCount($expectedCount, $rs, "operator {$op} {$val}");
            });
            $this->assertSame(0, $n, "operator {$op} should resolve from memory");
        }
    }

    #[Test]
    public function where_pivot_in_resolves_in_memory(): void
    {
        $post = $this->makePostWithTags(4);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $rs = $post->tags()->wherePivotIn('priority', [10, 30])->get();
            $this->assertCount(2, $rs);
        });

        $this->assertSame(0, $n);
    }

    #[Test]
    public function where_pivot_between_resolves_in_memory(): void
    {
        $post = $this->makePostWithTags(4);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            $rs = $post->tags()->wherePivotBetween('priority', [15, 35])->get();
            $this->assertCount(2, $rs);
        });

        $this->assertSame(0, $n);
    }

    // ------------------------------------------------------------------
    // Related-model where filter (non-pivot) on top of pivot coverage
    // ------------------------------------------------------------------

    #[Test]
    public function related_model_where_filter_resolves_in_memory(): void
    {
        $post = $this->makePostWithTags(4);
        $post->tags()->get();

        // Tag::name is unique per tag and only exists on `tags` (not on pivot) — no ambiguity.
        $allTags = $post->tags()->get();
        $names = $allTags->pluck('name')->all();
        sort($names);
        $targetName = $names[2];

        $n = $this->countQueries(function () use ($post, $targetName): void {
            $rs = $post->tags()->where('name', $targetName)->get();
            $this->assertCount(1, $rs);
        });

        $this->assertSame(0, $n);
    }

    // ------------------------------------------------------------------
    // isSafeGlobalScopeWhere — soft-delete WHERE IS NULL on related table
    // ------------------------------------------------------------------

    #[Test]
    public function soft_delete_null_where_on_tags_is_safe_and_serves_from_memory(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get(); // populates coverage

        $n = $this->countQueries(function () use ($post): void {
            $rs = $post->tags()->whereNull('tags.deleted_at')->get();
            $this->assertCount(3, $rs);
        });

        $this->assertSame(0, $n, 'isSafeGlobalScopeWhere must accept WHERE tags.deleted_at IS NULL so memory still serves');
    }

    #[Test]
    public function soft_delete_null_followed_by_extra_predicate_still_resolves_in_memory(): void
    {
        $post = $this->makePostWithTags(4);
        $post->tags()->get();

        $allTags = $post->tags()->get();
        $names = $allTags->pluck('name')->all();
        sort($names);
        $targetName = $names[1];

        $n = $this->countQueries(function () use ($post, $targetName): void {
            // A safe soft-delete where must `continue` rather than `break` so the
            // trailing name predicate is still extracted and applied.
            $rs = $post->tags()->whereNull('tags.deleted_at')->where('name', $targetName)->get();
            $this->assertCount(1, $rs);
        });

        $this->assertSame(0, $n);
    }

    // ------------------------------------------------------------------
    // extractExtraPredicates / isCleanLoad — unsupported boolean → SQL
    // ------------------------------------------------------------------

    #[Test]
    public function or_where_predicate_falls_back_to_sql(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();

        $n = $this->countQueries(function () use ($post): void {
            // orWhere is not parsed by extractExtraPredicates — must fall to SQL.
            // Use unambiguous `tags.id` columns to avoid the column-name collision.
            $post->tags()->where('tags.id', '>', 0)->orWhere('tags.id', '>', 1)->get();
        });

        $this->assertGreaterThan(0, $n);
    }

    // ------------------------------------------------------------------
    // detach + counter accuracy across multiple buckets
    // ------------------------------------------------------------------

    #[Test]
    public function detach_one_decrements_pivot_edge_count_accurately(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertSame(3, $this->graph->pivotEdgeCount());
        $tagIds = $post->tags()->pluck('tags.id')->all();

        $post->tags()->detach($tagIds[0]);
        $this->assertSame(2, $this->graph->pivotEdgeCount(), 'detach must decrement count');
        $this->assertCount(2, $this->graph->pivotEdgesFrom($identity, 'tags'));
    }

    #[Test]
    public function detach_all_zeros_pivot_edge_count(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();
        $this->assertSame(3, $this->graph->pivotEdgeCount());

        $post->tags()->detach();
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function invalidate_model_processes_all_pivot_buckets_when_post_has_multiple_pivot_relations(): void
    {
        // Two distinct posts each with their own pivot edges — invalidating Post class
        // must clear ALL buckets, not stop after the first.
        $postA = $this->makePostWithTags(2);
        $postB = $this->makePostWithTags(2);
        $postA->tags()->get();
        $postB->tags()->get();

        $idA = ModelIdentity::fromModel($postA);
        $idB = ModelIdentity::fromModel($postB);
        $this->assertNotNull($idA);
        $this->assertNotNull($idB);
        $this->assertCount(2, $this->graph->pivotEdgesFrom($idA, 'tags'));
        $this->assertCount(2, $this->graph->pivotEdgesFrom($idB, 'tags'));

        Post::query()->delete();

        $this->assertSame([], $this->graph->pivotEdgesFrom($idA, 'tags'));
        $this->assertSame([], $this->graph->pivotEdgesFrom($idB, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    #[Test]
    public function invalidate_model_processes_all_pivot_coverage_entries(): void
    {
        $postA = $this->makePostWithTags(1);
        $postB = $this->makePostWithTags(1);
        $postA->tags()->get();
        $postB->tags()->get();

        $idA = ModelIdentity::fromModel($postA);
        $idB = ModelIdentity::fromModel($postB);
        $this->assertNotNull($idA);
        $this->assertNotNull($idB);

        $this->assertSame(2, $this->graph->pivotCoverageCount());

        Tag::query()->delete();

        $this->assertNull($this->graph->pivotCoverageFor($idA, 'tags'));
        $this->assertNull($this->graph->pivotCoverageFor($idB, 'tags'));
        $this->assertSame(0, $this->graph->pivotCoverageCount());
    }

    // ------------------------------------------------------------------
    // sync proving coverage from many ids
    // ------------------------------------------------------------------

    #[Test]
    public function sync_with_many_ids_proves_coverage_and_serves_subsequent_reads(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'usync@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Tag::create(['name' => "syncTag-{$i}"])->id;
        }

        $post->tags()->sync($ids);

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $coverage = $this->graph->pivotCoverageFor($identity, 'tags');
        $this->assertNotNull($coverage);
        $this->assertTrue($coverage->complete);
        $this->assertSame(5, $this->graph->pivotEdgeCount());

        $n = $this->countQueries(function () use ($post): void {
            $rs = $post->tags()->get();
            $this->assertCount(5, $rs);
        });
        $this->assertSame(0, $n);
    }

    // ------------------------------------------------------------------
    // toggle and syncWithoutDetaching: both blanket-invalidate
    // ------------------------------------------------------------------

    #[Test]
    public function toggle_invalidates_coverage_and_edges(): void
    {
        $post = $this->makePostWithTags(2);
        $post->tags()->get();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));
        $this->assertSame(2, $this->graph->pivotEdgeCount());

        $newTag = Tag::create(['name' => 'toggleNew']);
        $post->tags()->toggle([$newTag->id]);

        $this->assertNull($this->graph->pivotCoverageFor($identity, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount(), 'toggle must clear edges');
    }

    #[Test]
    public function sync_without_detaching_invalidates_coverage_and_edges(): void
    {
        $post = $this->makePostWithTags(2);
        $post->tags()->get();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));

        $newTag = Tag::create(['name' => 'syncWoDetNew']);
        $post->tags()->syncWithoutDetaching([$newTag->id]);

        $this->assertNull($this->graph->pivotCoverageFor($identity, 'tags'));
        $this->assertSame(0, $this->graph->pivotEdgeCount());
    }

    // ------------------------------------------------------------------
    // Confidence + EdgeSource discriminators
    // ------------------------------------------------------------------

    #[Test]
    public function full_load_records_edges_with_certain_pivot_source(): void
    {
        $post = $this->makePostWithTags(2);
        $post->tags()->get();
        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);

        $edges = $this->graph->pivotEdgesFrom($identity, 'tags');
        $this->assertCount(2, $edges);
        foreach ($edges as $e) {
            $this->assertSame(
                EdgeSource::Pivot,
                $e->source,
                'full load must mark pivot source',
            );
            $this->assertSame(
                EdgeConfidence::Certain,
                $e->confidence,
                'full load is certain',
            );
            $this->assertArrayHasKey('active', $e->pivotAttributes);
            $this->assertArrayHasKey('priority', $e->pivotAttributes);
        }
    }

    #[Test]
    public function pivot_coverage_records_correct_pivot_table_and_columns(): void
    {
        $post = $this->makePostWithTags(1);
        $post->tags()->get();
        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);

        $coverage = $this->graph->pivotCoverageFor($identity, 'tags');
        $this->assertNotNull($coverage);
        $this->assertSame('post_tag', $coverage->pivotTable);
        $this->assertContains('post_id', $coverage->knownPivotColumns);
        $this->assertContains('tag_id', $coverage->knownPivotColumns);
        $this->assertContains('active', $coverage->knownPivotColumns);
        $this->assertContains('priority', $coverage->knownPivotColumns);
        // knownPivotColumns must dedupe: foreignPivotKey + relatedPivotKey + pivotColumns may overlap.
        $this->assertCount(count(array_unique($coverage->knownPivotColumns)), $coverage->knownPivotColumns);
    }

    // ------------------------------------------------------------------
    // detach by Model / Collection input
    // ------------------------------------------------------------------

    #[Test]
    public function detach_by_model_instance_removes_that_edge(): void
    {
        $post = $this->makePostWithTags(2);
        $post->tags()->get();
        $tags = $post->tags()->get();
        $first = $tags->first();
        $this->assertNotNull($first);

        $post->tags()->detach($first);

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertCount(1, $this->graph->pivotEdgesFrom($identity, 'tags'));
    }

    // ------------------------------------------------------------------
    // pivot must not leak: serving two parents from memory that share a related
    // Tag must NOT mutate the canonical identity-map instance.
    // ------------------------------------------------------------------

    #[Test]
    public function memory_served_get_does_not_mutate_canonical_related_instance(): void
    {
        $tag = Tag::create(['name' => 'shared']);

        $user1 = User::create(['name' => 'U1', 'email' => 'shared1@example.com']);
        $post1 = Post::create(['user_id' => $user1->id, 'title' => 'P1']);
        $post1->tags()->attach($tag, ['active' => true, 'priority' => 11]);

        $user2 = User::create(['name' => 'U2', 'email' => 'shared2@example.com']);
        $post2 = Post::create(['user_id' => $user2->id, 'title' => 'P2']);
        $post2->tags()->attach($tag, ['active' => false, 'priority' => 99]);

        // Prime both posts' pivot coverage from SQL.
        $post1->tags()->get();
        $post2->tags()->get();

        // Serve from memory for post1, then post2 — each must return distinct pivot.
        // Cannot use `->first()` on the relation: that bypasses our `get()` override.
        $this->assertSame(11, $this->priorityOfFirstTagPivot($post1));
        $this->assertSame(99, $this->priorityOfFirstTagPivot($post2));

        // Re-read post1 — pivot must still reflect post1's edge, NOT be overwritten
        // by the post2 read above.
        $this->assertSame(
            11,
            $this->priorityOfFirstTagPivot($post1),
            'serving post2 must not have mutated the canonical Tag instance held in the identity map',
        );
    }

    private function priorityOfFirstTagPivot(Post $post): ?int
    {
        $tags = $post->tags()->get();
        foreach ($tags as $tag) {
            $pivot = $tag->getRelation('pivot');
            if (! $pivot instanceof Model) {
                return null;
            }
            $val = $pivot->getAttribute('priority');

            return is_numeric($val) ? (int) $val : null;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // detach by Illuminate Collection input is parsed the same as parent::detach
    // ------------------------------------------------------------------

    #[Test]
    public function detach_by_eloquent_collection_removes_those_edges(): void
    {
        $post = $this->makePostWithTags(3);
        $post->tags()->get();
        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertSame(3, $this->graph->pivotEdgeCount());

        $tagsToDetach = $post->tags()->limit(2)->get();
        $post->tags()->detach($tagsToDetach);

        $this->assertSame(
            1,
            $this->graph->pivotEdgeCount(),
            'detach by Collection must remove edges through the graph (parseIds delegation)',
        );
    }

    // ------------------------------------------------------------------
    // SoftDeletes interaction on parent: deleting parent invalidates pivot coverage
    // ------------------------------------------------------------------

    #[Test]
    public function deleting_parent_invalidates_its_pivot_coverage(): void
    {
        $post = $this->makePostWithTags(2);
        $post->tags()->get();
        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));

        $post->delete();

        $this->assertNull(
            $this->graph->pivotCoverageFor($identity, 'tags'),
            'deleting the parent must invalidate its pivot coverage',
        );
    }
}
