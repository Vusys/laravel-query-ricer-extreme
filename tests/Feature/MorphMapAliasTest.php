<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * With an active morph map (`Relation::morphMap` / `enforceMorphMap`) the
 * polymorphic type column stores an alias instead of the FQCN. Memory-served
 * morph reads, graph edges and whereHas must all resolve correctly under the
 * alias and match SQL.
 */
final class MorphMapAliasTest extends TestCase
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

        Relation::morphMap(['user' => User::class], false);
    }

    #[\Override]
    protected function tearDown(): void
    {
        // Reset the global morph-map state so it cannot leak into other tests.
        Relation::morphMap([], false);
        Relation::requireMorphMap(false);
        parent::tearDown();
    }

    #[Test]
    public function aliased_type_is_stored_in_the_database(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create(['commentable_type' => $user->getMorphClass(), 'commentable_id' => $user->id, 'body' => 'hi']);

        $this->assertSame('user', $comment->commentable_type, 'morph map must store the alias, not the FQCN');
    }

    #[Test]
    public function morph_to_resolves_alias_and_serves_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create(['commentable_type' => $user->getMorphClass(), 'commentable_id' => $user->id, 'body' => 'hi']);

        // Warm both sides.
        User::find($user->id);
        Comment::find($comment->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $resolved = $comment->commentable;

        $this->assertSame(0, $queryCount, 'aliased morphTo must serve from memory');
        $this->assertInstanceOf(User::class, $resolved);
        $this->assertSame($user->id, $resolved->getKey());
    }

    #[Test]
    public function graph_edge_keyed_under_alias_serves_inverse_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create(['commentable_type' => $user->getMorphClass(), 'commentable_id' => $user->id, 'body' => 'hi']);

        // Load the forward relation to build graph coverage, then read the inverse.
        $user->load('comments');
        Comment::find($comment->id);

        $resolved = $comment->commentable;
        $this->assertInstanceOf(User::class, $resolved);
        $this->assertSame($user->id, $resolved->getKey());
    }

    #[Test]
    public function where_has_morph_relation_under_alias_matches_oracle(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
        Comment::create(['commentable_type' => $alice->getMorphClass(), 'commentable_id' => $alice->id, 'body' => 'keep', 'likes' => 5]);
        Comment::create(['commentable_type' => $bob->getMorphClass(), 'commentable_id' => $bob->id, 'body' => 'drop', 'likes' => 0]);

        $alice->load('comments');
        $bob->load('comments');

        $ricer = User::whereHas('comments', fn ($q) => $q->where('likes', '>', 1))
            ->get()->pluck('id')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn (): array => User::whereHas('comments', fn ($q) => $q->where('likes', '>', 1))
                ->get()->pluck('id')->sort()->values()->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame([$alice->id], $ricer);
    }
}
