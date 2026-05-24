<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Graph;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class RelationGraphCoverageTest extends TestCase
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

    #[Test]
    public function load_records_complete_graph_coverage_for_has_many(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $user->load('posts');

        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $coverage = $this->graph->coverageFor($identity, 'posts');

        $this->assertNotNull($coverage);
        $this->assertTrue($coverage->complete);
        $this->assertCount(2, $coverage->childPrimaryKeys);
        $this->assertTrue($coverage->columns->allColumns, 'load() records ColumnSet([*])');
        $this->assertCount(2, $this->graph->edgesFrom($identity, 'posts'), 'one edge per loaded child');
    }

    #[Test]
    public function eager_load_records_graph_coverage_via_match_many(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $this->graph->flush();

        $loaded = User::with('posts')->findOrFail($user->id);

        $identity = ModelIdentity::fromModel($loaded);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->coverageFor($identity, 'posts'));
        $this->assertCount(1, $this->graph->edgesFrom($identity, 'posts'), 'matchMany records one edge per child');
    }

    #[Test]
    public function graph_coverage_serves_get_when_relation_not_loaded_on_instance(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $user->load('posts');
        $user->unsetRelation('posts');
        $this->assertFalse($user->relationLoaded('posts'));

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get();

        $this->assertSame(0, $queryCount, 'graph coverage should serve get() without SQL');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function graph_coverage_filters_with_extra_predicate(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P3', 'published' => false]);

        $user->load('posts');
        $user->unsetRelation('posts');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $published = $user->posts()->where('published', true)->get();

        $this->assertSame(0, $queryCount, 'graph coverage should filter without SQL');
        $this->assertCount(2, $published);
    }

    #[Test]
    public function graph_coverage_serves_morph_many_get_when_relation_not_loaded(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'c1']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'c2']);

        $user->load('comments');

        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertCount(
            2,
            $this->graph->edgesFrom($identity, 'comments'),
            'morph-many records one edge per child',
        );

        $user->unsetRelation('comments');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->comments()->get();

        $this->assertSame(0, $queryCount, 'morph-many graph coverage should serve get() without SQL');
        $this->assertCount(2, $result);
    }

    #[Test]
    public function belongs_to_records_fk_inferred_edge_on_memory_hit(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $freshPost = Post::findOrFail($post->id);
        $this->assertNotNull($freshPost->user);

        $childIdentity = ModelIdentity::fromModel($freshPost);
        $this->assertNotNull($childIdentity);
        $edges = $this->graph->edgesFrom($childIdentity, 'user');

        $this->assertCount(1, $edges);
        $this->assertSame($user->id, $edges[0]->to->primaryKeyValue);
    }

    #[Test]
    public function disabling_relation_graph_via_config_skips_population(): void
    {
        config(['query-ricer-extreme.relation_graph.enabled' => false]);

        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');

        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertNull($this->graph->coverageFor($identity, 'posts'));
    }

    #[Test]
    public function model_identity_from_model_uses_explicit_fingerprint_when_provided(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);

        $identity = ModelIdentity::fromModel($user, 'custom-fingerprint');

        $this->assertNotNull($identity);
        $this->assertSame('custom-fingerprint', $identity->scopeFingerprint);
    }

    #[Test]
    public function model_identity_from_model_uses_actual_connection_name(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $user->setConnection('explicit-name');

        $identity = ModelIdentity::fromModel($user, 'fp');

        $this->assertNotNull($identity);
        $this->assertSame('explicit-name', $identity->connection);
    }

    #[Test]
    public function graph_coverage_falls_back_to_sql_when_child_evicted_from_store(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $post1 = Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $user->load('posts');
        $user->unsetRelation('posts');
        $this->store->forget($post1);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $user->posts()->get();

        $this->assertGreaterThan(0, $queryCount, 'graph coverage must fall back when a recorded child is missing from the store');
        $this->assertCount(2, $result);
    }
}
