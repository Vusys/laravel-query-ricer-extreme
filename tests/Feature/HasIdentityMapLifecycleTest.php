<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Pins the side-effects of the {@see HasIdentityMap}
 * model-event closures: every lifecycle transition must invalidate any caches
 * that could observe stale state for the affected model class.
 */
final class HasIdentityMapLifecycleTest extends TestCase
{
    private IdentityMapStore $store;

    private CoverageRegistry $registry;

    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->registry = resolve(CoverageRegistry::class);
        $this->graph = resolve(IdentityGraph::class);

        $this->store->flush();
        $this->registry->flush();
        $this->graph->flush();
    }

    #[Test]
    public function deleted_callback_flushes_coverage_for_the_model_class(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::where('name', 'Alice')->get();

        $this->assertSame(1, $this->registry->entryCount());

        $alice->delete();

        $this->assertSame(
            0,
            $this->registry->entryCount(),
            'deleted callback must flush CoverageRegistry for the model class',
        );
    }

    #[Test]
    public function deleted_callback_invalidates_identity_graph_for_the_model_class(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $alice->id, 'title' => 'p1']);
        Post::create(['user_id' => $alice->id, 'title' => 'p2']);
        User::with('posts')->find($alice->id);

        $this->assertGreaterThan(0, $this->graph->totalEdgeCount());

        $alice->delete();

        $this->assertSame(
            0,
            $this->graph->totalEdgeCount(),
            'deleted callback must invalidate IdentityGraph for the model class',
        );
    }

    #[Test]
    public function restored_callback_flushes_coverage_for_the_model_class(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $alice->delete();

        User::onlyTrashed()->where('name', 'Alice')->get();
        $this->assertSame(1, $this->registry->entryCount());

        $alice->restore();

        $this->assertSame(
            0,
            $this->registry->entryCount(),
            'restored callback must flush CoverageRegistry for the model class',
        );
    }

    #[Test]
    public function restored_callback_invalidates_identity_graph_for_the_model_class(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $alice->id, 'title' => 'p1']);
        User::with('posts')->find($alice->id);
        $alice->delete();
        $this->graph->flush();

        User::onlyTrashed()->find($alice->id);
        Post::create(['user_id' => $alice->id, 'title' => 'p2']);
        User::with('posts')->onlyTrashed()->find($alice->id);

        $this->assertGreaterThan(0, $this->graph->totalEdgeCount());

        $alice->restore();

        $this->assertSame(
            0,
            $this->graph->totalEdgeCount(),
            'restored callback must invalidate IdentityGraph for the model class',
        );
    }
}
