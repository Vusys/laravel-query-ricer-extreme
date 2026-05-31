<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Performance;

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;

/**
 * Benchmarks targeting the engine's hot paths so future optimisation PRs have
 * regression signal beyond the baseline find / absent benches. Each test isolates
 * one subsystem (coverage, key-set, predicate evaluation, mass writes, graph,
 * partial-model backfill, observability overhead, populated-map flush).
 */
#[Group('performance')]
final class OptimizationBenchmarkTest extends PerformanceTestCase
{
    #[Test]
    public function coverage_served_get(): void
    {
        User::factory()->count(20)->create();

        $this->bench('coverage-served-get', function (): void {
            User::query()->where('active', true)->get();
            for ($i = 0; $i < 99; $i++) {
                User::query()->where('active', true)->get();
            }
        });
    }

    #[Test]
    public function coverage_served_narrow_first_after_priming(): void
    {
        User::factory()->count(10)->create(['active' => true, 'score' => 50]);
        User::factory()->count(10)->create(['active' => true, 'score' => 90]);

        $this->bench('coverage-narrow-first', function (): void {
            User::query()->where('active', true)->get();
            for ($i = 0; $i < 100; $i++) {
                User::query()->where('active', true)->where('score', 90)->first();
            }
        });
    }

    #[Test]
    public function keyset_partial_hit(): void
    {
        $users = User::factory()->count(20)->create();
        /** @var list<int> $ids */
        $ids = [];
        foreach ($users as $u) {
            $ids[] = $u->id;
        }
        $hotKeys = array_slice($ids, 0, 10);
        $maxId = $ids === [] ? 0 : max($ids);
        $coldKeys = range($maxId + 1, $maxId + 10);
        $keys = array_merge($hotKeys, $coldKeys);

        $this->bench('keyset-partial-hit', function () use ($keys, $hotKeys): void {
            User::find($hotKeys);
            for ($i = 0; $i < 100; $i++) {
                User::find($keys);
            }
        });
    }

    #[Test]
    public function unique_key_first(): void
    {
        User::factory()->create(['email' => 'hot@example.com']);

        $this->bench('unique-key-first', function (): void {
            User::query()->where('email', 'hot@example.com')->first();
            for ($i = 0; $i < 99; $i++) {
                User::query()->where('email', 'hot@example.com')->first();
            }
        });
    }

    #[Test]
    public function wherehas_served_from_graph(): void
    {
        $user = User::factory()->create();
        Post::factory()->count(5)->for($user)->create(['published' => true]);

        $this->bench('wherehas-graph', function (): void {
            User::query()->with('posts')->get();
            for ($i = 0; $i < 100; $i++) {
                User::query()->whereHas('posts')->get();
            }
        });
    }

    #[Test]
    public function belongsto_served_from_graph(): void
    {
        $user = User::factory()->create();
        $posts = Post::factory()->count(10)->for($user)->create();

        $this->bench('belongsto-graph', function () use ($posts): void {
            $touched = 0;
            foreach ($posts as $post) {
                $touched += $post->user instanceof User ? 1 : 0;
            }
            for ($i = 0; $i < 50; $i++) {
                foreach ($posts as $post) {
                    $touched += $post->user instanceof User ? 1 : 0;
                }
            }
        });
    }

    #[Test]
    public function mass_update_with_preloaded_entries(): void
    {
        User::factory()->count(50)->create(['active' => true]);

        $this->bench('mass-update-preloaded', function (): void {
            User::query()->get();
            for ($i = 0; $i < 20; $i++) {
                User::query()->where('active', true)->update(['score' => 42 + $i]);
            }
        });
    }

    #[Test]
    public function streaming_disabled_overhead(): void
    {
        $user = User::factory()->create();
        $id = $user->id;
        config(['query-ricer-extreme.observability.enabled' => false]);

        $this->bench('streaming-disabled', function () use ($id): void {
            for ($i = 0; $i < 500; $i++) {
                User::find($id);
            }
        });
    }

    #[Test]
    public function streaming_enabled_overhead(): void
    {
        $user = User::factory()->create();
        $id = $user->id;
        config([
            'query-ricer-extreme.observability.enabled' => true,
            'logging.default' => 'null',
        ]);
        Event::fake();

        try {
            $this->bench('streaming-enabled', function () use ($id): void {
                for ($i = 0; $i < 500; $i++) {
                    User::find($id);
                }
            });
        } finally {
            config(['query-ricer-extreme.observability.enabled' => false]);
        }
    }

    #[Test]
    public function flush_model_class_on_populated_map(): void
    {
        $userIds = User::factory()->count(100)->create()->pluck('id')->all();
        $tagIds = Tag::factory()->count(100)->create()->pluck('id')->all();
        $store = resolve(IdentityMapStore::class);

        $this->bench('flush-model-class', function () use ($userIds, $tagIds, $store): void {
            foreach ($userIds as $id) {
                User::find($id);
            }
            foreach ($tagIds as $id) {
                Tag::find($id);
            }
            for ($i = 0; $i < 50; $i++) {
                $store->flush(User::class);
                foreach ($userIds as $id) {
                    User::find($id);
                }
            }
        });
    }

    #[Test]
    public function graph_invalidate_after_bulk_change(): void
    {
        $users = User::factory()->count(20)->create();
        foreach ($users as $u) {
            Post::factory()->count(3)->for($u)->create();
        }
        $graph = resolve(IdentityGraph::class);

        $this->bench('graph-invalidate', function () use ($graph): void {
            User::query()->with('posts')->get();
            for ($i = 0; $i < 50; $i++) {
                $graph->invalidateModelClass(Post::class);
                User::query()->with('posts')->get();
            }
        });
    }

    #[Test]
    public function partial_model_backfill(): void
    {
        config(['query-ricer-extreme.partial_models' => 'backfill_missing_columns']);
        $user = User::factory()->create();
        $id = $user->id;

        try {
            $this->bench('partial-model-backfill', function () use ($id): void {
                User::query()->select(['id', 'email'])->whereKey($id)->first();
                for ($i = 0; $i < 100; $i++) {
                    User::query()->select(['id', 'email', 'name'])->whereKey($id)->first();
                }
            });
        } finally {
            config(['query-ricer-extreme.partial_models' => 'query_normally']);
        }
    }

    #[Test]
    public function coverage_findcovering_on_populated_registry(): void
    {
        User::factory()->count(30)->create();

        $this->bench('coverage-findcovering', function (): void {
            for ($i = 0; $i < 30; $i++) {
                User::query()->where('score', '>=', $i)->get();
            }
            for ($i = 0; $i < 200; $i++) {
                User::query()->where('score', '>=', 15)->get();
            }
        });
    }
}
