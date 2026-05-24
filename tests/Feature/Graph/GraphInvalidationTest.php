<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Graph;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class GraphInvalidationTest extends TestCase
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
    public function mass_update_invalidates_graph_for_model_class(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $user->load('posts');
        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->coverageFor($identity, 'posts'));

        Post::where('published', true)->update(['published' => false]);

        $this->assertNull($this->graph->coverageFor($identity, 'posts'));
        $this->assertSame([], $this->graph->edgesFrom($identity, 'posts'));
    }

    #[Test]
    public function mass_delete_invalidates_graph_for_model_class(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');
        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->coverageFor($identity, 'posts'));

        Post::query()->delete();

        $this->assertNull($this->graph->coverageFor($identity, 'posts'));
    }

    #[Test]
    public function single_save_of_new_child_invalidates_parent_graph_coverage(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');
        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->coverageFor($identity, 'posts'));

        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $this->assertNull(
            $this->graph->coverageFor($identity, 'posts'),
            'creating a new child must invalidate stale parent coverage',
        );
    }

    #[Test]
    public function single_delete_invalidates_parent_graph_coverage(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');
        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->coverageFor($identity, 'posts'));

        $post->delete();

        $this->assertNull($this->graph->coverageFor($identity, 'posts'));
    }

    #[Test]
    public function flush_clears_the_graph_on_scope_boundary(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');
        $this->assertGreaterThan(0, $this->graph->coverageCount());

        $this->graph->flush();

        $this->assertSame(0, $this->graph->coverageCount());
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function mass_update_on_parent_invalidates_parent_class_graph_coverage(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);

        $user->load('posts');
        $identity = ModelIdentity::fromModel($user);
        $this->assertNotNull($identity);
        $this->assertNotNull($this->graph->coverageFor($identity, 'posts'));

        User::where('id', $user->id)->update(['name' => 'B']);

        $this->assertNull(
            $this->graph->coverageFor($identity, 'posts'),
            'mass update on User must invalidate coverage where User is the parent class',
        );
    }
}
