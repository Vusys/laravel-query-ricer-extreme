<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Graph;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class BelongsToManyGraphTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    private int $userSeq = 0;

    private function postWithTags(int $tagCount = 2): Post
    {
        $this->userSeq++;
        $user = User::create(['name' => "U{$this->userSeq}", 'email' => "u{$this->userSeq}@example.com"]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);

        for ($i = 1; $i <= $tagCount; $i++) {
            $tag = Tag::create(['name' => "t{$this->userSeq}-{$i}", 'priority' => $i]);
            $post->tags()->attach($tag, ['active' => $i % 2 === 1, 'priority' => $i]);
        }

        return $post;
    }

    private function countQueries(callable $cb): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $cb();

        return $count;
    }

    #[Test]
    public function full_load_records_complete_pivot_coverage(): void
    {
        $post = $this->postWithTags(3);
        $this->graph->flush();

        $tags = $post->tags()->get();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $coverage = $this->graph->pivotCoverageFor($identity, 'tags');
        $this->assertNotNull($coverage);
        $this->assertTrue($coverage->complete);
        $this->assertCount(3, $tags);
        $this->assertCount(3, $this->graph->pivotEdgesFrom($identity, 'tags'));
    }

    #[Test]
    public function eager_load_records_pivot_coverage_for_each_parent(): void
    {
        $postA = $this->postWithTags(2);
        $postB = $this->postWithTags(1);
        $this->graph->flush();

        $loaded = Post::with('tags')->whereIn('id', [$postA->id, $postB->id])->get();

        $this->assertCount(2, $loaded);

        foreach ($loaded as $p) {
            $id = ModelIdentity::fromModel($p);
            $this->assertNotNull($id);
            $this->assertNotNull(
                $this->graph->pivotCoverageFor($id, 'tags'),
                "eager-loaded post {$p->id} should have pivot coverage",
            );
        }
    }

    #[Test]
    public function second_get_after_full_load_skips_sql(): void
    {
        $post = $this->postWithTags(2);
        $post->tags()->get(); // populates coverage

        $queryCount = $this->countQueries(function () use ($post): void {
            $result = $post->tags()->get();
            $this->assertCount(2, $result);
        });

        $this->assertSame(0, $queryCount, 'covered get() should skip SQL');
    }

    #[Test]
    public function where_pivot_filter_evaluates_in_memory(): void
    {
        $post = $this->postWithTags(4); // odd ids active, even ids inactive
        $post->tags()->get();

        $queryCount = $this->countQueries(function () use ($post): void {
            $active = $post->tags()->wherePivot('active', true)->get();
            $this->assertCount(2, $active);
        });

        $this->assertSame(0, $queryCount, 'wherePivot should resolve from memory when coverage is known');
    }

    #[Test]
    public function where_pivot_comparison_resolves_from_memory(): void
    {
        $post = $this->postWithTags(4); // priorities 1..4
        $post->tags()->get();

        $queryCount = $this->countQueries(function () use ($post): void {
            $high = $post->tags()->wherePivot('priority', '>', 2)->get();
            $this->assertCount(2, $high);
        });

        $this->assertSame(0, $queryCount);
    }

    #[Test]
    public function attach_does_not_prove_coverage(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $tag = Tag::create(['name' => 'x']);

        $post->tags()->attach($tag, ['active' => true]);

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNull(
            $this->graph->pivotCoverageFor($identity, 'tags'),
            'attach alone must not prove pivot coverage',
        );

        $queryCount = $this->countQueries(function () use ($post): void {
            $post->tags()->get();
        });
        $this->assertGreaterThan(0, $queryCount, 'attach-only must still hit SQL on read');
    }

    #[Test]
    public function attach_after_complete_coverage_invalidates_coverage(): void
    {
        $post = $this->postWithTags(2);
        $post->tags()->get(); // coverage = complete

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));

        $newTag = Tag::create(['name' => 'fresh']);
        $post->tags()->attach($newTag, ['active' => true]);

        $this->assertNull(
            $this->graph->pivotCoverageFor($identity, 'tags'),
            'attach after coverage must invalidate the stale complete claim',
        );
    }

    #[Test]
    public function sync_with_intended_set_proves_coverage(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $tagA = Tag::create(['name' => 'a']);
        $tagB = Tag::create(['name' => 'b']);

        $post->tags()->sync([
            $tagA->id => ['active' => true, 'priority' => 1],
            $tagB->id => ['active' => false, 'priority' => 2],
        ]);

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $coverage = $this->graph->pivotCoverageFor($identity, 'tags');
        $this->assertNotNull($coverage, 'sync must prove pivot coverage');
        $this->assertTrue($coverage->complete);

        $queryCount = $this->countQueries(function () use ($post): void {
            $result = $post->tags()->get();
            $this->assertCount(2, $result);
        });
        $this->assertSame(0, $queryCount, 'sync-proven coverage should skip SQL');
    }

    #[Test]
    public function sync_with_simple_ids_proves_coverage(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        $tagA = Tag::create(['name' => 'a']);
        $tagB = Tag::create(['name' => 'b']);

        $post->tags()->sync([$tagA->id, $tagB->id]);

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $coverage = $this->graph->pivotCoverageFor($identity, 'tags');
        $this->assertNotNull($coverage);
        $this->assertTrue($coverage->complete);
    }

    #[Test]
    public function detach_specific_ids_preserves_coverage(): void
    {
        $post = $this->postWithTags(3);
        $post->tags()->get();
        $tagIds = $post->tags()->pluck('tags.id')->all();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));

        $post->tags()->detach($tagIds[0]);

        $this->assertNotNull(
            $this->graph->pivotCoverageFor($identity, 'tags'),
            'detach with specific ids should preserve coverage',
        );

        $queryCount = $this->countQueries(function () use ($post): void {
            $result = $post->tags()->get();
            $this->assertCount(2, $result);
        });
        $this->assertSame(0, $queryCount);
    }

    #[Test]
    public function detach_all_proves_empty_coverage(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P']);
        // No prior load — just attach a few then detach all.
        $tag = Tag::create(['name' => 'x']);
        $post->tags()->attach($tag);

        $post->tags()->detach();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $coverage = $this->graph->pivotCoverageFor($identity, 'tags');
        $this->assertNotNull($coverage);
        $this->assertTrue($coverage->complete);

        $queryCount = $this->countQueries(function () use ($post): void {
            $result = $post->tags()->get();
            $this->assertCount(0, $result);
        });
        $this->assertSame(0, $queryCount);
    }

    #[Test]
    public function disabled_graph_skips_pivot_population(): void
    {
        config(['query-ricer-extreme.relation_graph.enabled' => false]);

        $post = $this->postWithTags(2);
        $post->tags()->get();

        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNull($this->graph->pivotCoverageFor($identity, 'tags'));
    }

    #[Test]
    public function deleting_related_model_invalidates_pivot_coverage(): void
    {
        $post = $this->postWithTags(2);
        $post->tags()->get();
        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));

        // Mass-delete the Tag class — the pivot coverage references it.
        Tag::query()->delete();

        $this->assertNull(
            $this->graph->pivotCoverageFor($identity, 'tags'),
            'mass-deleting the related class must invalidate pivot coverage',
        );
    }

    #[Test]
    public function single_save_of_new_tag_invalidates_pivot_coverage(): void
    {
        $post = $this->postWithTags(2);
        $post->tags()->get();
        $identity = ModelIdentity::fromModel($post);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->pivotCoverageFor($identity, 'tags'));

        // HasIdentityMap's `saved` listener flushes by *related* model class on
        // wasRecentlyCreated, so creating any Tag — even one unrelated to this
        // post — invalidates the post's pivot coverage. Overly conservative
        // but safe; documenting the current observable behaviour.
        Tag::create(['name' => 'unrelated']);

        $this->assertNull($this->graph->pivotCoverageFor($identity, 'tags'));
    }

    #[Test]
    public function update_existing_pivot_invalidates_stale_pivot_attributes_in_memory(): void
    {
        $post = $this->postWithTags(1);
        $tag = $post->tags->first();
        $this->assertNotNull($tag);

        // Warm: graph has pivot edge with priority = 1 (from postWithTags seed).
        $hits = $post->tags()->wherePivot('priority', 1)->get();
        $this->assertCount(1, $hits);

        // Update the pivot row — the DB now has priority = 99 but the cached
        // PivotEdge still holds the old value.
        $post->tags()->updateExistingPivot($tag->id, ['priority' => 99]);

        $hitsAfter = $post->tags()->wherePivot('priority', 99)->get();

        $this->assertCount(1, $hitsAfter, 'updateExistingPivot must keep cached pivot attributes coherent with the DB');
    }

    #[Test]
    public function update_existing_pivot_invalidates_inverse_side_too(): void
    {
        $post = $this->postWithTags(1);
        $tag = $post->tags->first();
        $this->assertNotNull($tag);

        // Warm both sides with the *complete* set so pivot coverage is
        // recorded on each (wherePivot filters skip coverage recording).
        $post->tags()->get();
        $tag->posts()->get();

        // Update via the post side. The inverse (tag->posts) must also be
        // invalidated, since both sides read the same pivot row.
        $post->tags()->updateExistingPivot($tag->id, ['priority' => 99]);

        $fromInverse = $tag->posts()->wherePivot('priority', 99)->get();

        $this->assertCount(
            1,
            $fromInverse,
            'updateExistingPivot must also flush the inverse BelongsToMany on the related model',
        );
    }
}
