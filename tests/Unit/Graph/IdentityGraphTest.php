<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Graph;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Graph\EdgeConfidence;
use Vusys\QueryRicerExtreme\Graph\EdgeSource;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Graph\RelationCoverage;
use Vusys\QueryRicerExtreme\Graph\RelationEdge;

final class IdentityGraphTest extends TestCase
{
    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        $this->graph = new IdentityGraph;
    }

    private function userIdentity(int $id = 1): ModelIdentity
    {
        return new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: $id,
            scopeFingerprint: 'default',
        );
    }

    private function postIdentity(int $id = 10): ModelIdentity
    {
        return new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Post',
            table: 'posts',
            primaryKeyName: 'id',
            primaryKeyValue: $id,
            scopeFingerprint: 'default',
        );
    }

    private function makeEdge(ModelIdentity $from, ModelIdentity $to, string $name = 'posts'): RelationEdge
    {
        return new RelationEdge(
            from: $from,
            relationName: $name,
            kind: RelationKind::HasMany,
            to: $to,
            source: EdgeSource::LoadedRelation,
            confidence: EdgeConfidence::Certain,
            version: 1,
        );
    }

    /**
     * @param  list<int|string>  $childPrimaryKeys
     */
    private function makeCoverage(
        ModelIdentity $parent,
        string $relationName = 'posts',
        string $relatedModelClass = 'App\\Models\\Post',
        array $childPrimaryKeys = [],
    ): RelationCoverage {
        return new RelationCoverage(
            parent: $parent,
            relationName: $relationName,
            relatedModelClass: $relatedModelClass,
            complete: true,
            columns: new ColumnSet(['*']),
            childPrimaryKeys: $childPrimaryKeys,
        );
    }

    #[Test]
    public function add_and_query_edges_round_trip(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $edge = $this->makeEdge($user, $post);

        $this->graph->addEdge($edge);

        $this->assertSame([$edge], $this->graph->edgesFrom($user, 'posts'));
        $this->assertSame(1, $this->graph->edgeCount());
    }

    #[Test]
    public function adding_same_edge_twice_updates_in_place(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $first = $this->makeEdge($user, $post);
        $second = $this->makeEdge($user, $post);
        $second->version = 7;

        $this->graph->addEdge($first);
        $this->graph->addEdge($second);

        $edges = $this->graph->edgesFrom($user, 'posts');
        $this->assertCount(1, $edges);
        $this->assertSame($second, $edges[0]);
        $this->assertSame(1, $this->graph->edgeCount());
    }

    #[Test]
    public function edges_from_returns_empty_for_unknown_parent(): void
    {
        $this->assertSame([], $this->graph->edgesFrom($this->userIdentity(), 'posts'));
    }

    #[Test]
    public function add_and_query_coverage_round_trip(): void
    {
        $user = $this->userIdentity();
        $coverage = $this->makeCoverage($user, childPrimaryKeys: [10, 11]);

        $this->graph->addCoverage($coverage);

        $this->assertSame($coverage, $this->graph->coverageFor($user, 'posts'));
        $this->assertSame(1, $this->graph->coverageCount());
    }

    #[Test]
    public function coverage_lookup_returns_null_when_relation_name_differs(): void
    {
        $user = $this->userIdentity();
        $this->graph->addCoverage($this->makeCoverage($user, relationName: 'posts'));

        $this->assertNull($this->graph->coverageFor($user, 'comments'));
    }

    #[Test]
    public function invalidate_model_removes_outgoing_edges_and_coverage(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $this->graph->addEdge($this->makeEdge($user, $post));
        $this->graph->addCoverage($this->makeCoverage($user, childPrimaryKeys: [10]));

        $this->graph->invalidateModel($user);

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertNull($this->graph->coverageFor($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function invalidate_model_removes_incoming_edges_only_for_that_model(): void
    {
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $post = $this->postIdentity(10);

        $this->graph->addEdge($this->makeEdge($userA, $post));
        $this->graph->addEdge($this->makeEdge($userB, $post));

        $this->graph->invalidateModel($post);

        $this->assertSame([], $this->graph->edgesFrom($userA, 'posts'));
        $this->assertSame([], $this->graph->edgesFrom($userB, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function invalidate_model_leaves_unrelated_data_intact(): void
    {
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);

        $this->graph->addEdge($this->makeEdge($userA, $postA));
        $edgeB = $this->makeEdge($userB, $postB);
        $this->graph->addEdge($edgeB);
        $coverageB = $this->makeCoverage($userB, childPrimaryKeys: [20]);
        $this->graph->addCoverage($coverageB);

        $this->graph->invalidateModel($userA);

        $this->assertSame([$edgeB], $this->graph->edgesFrom($userB, 'posts'));
        $this->assertSame($coverageB, $this->graph->coverageFor($userB, 'posts'));
    }

    #[Test]
    public function invalidate_model_class_removes_edges_and_coverage_on_either_side(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();

        $this->graph->addEdge($this->makeEdge($user, $post));
        $this->graph->addCoverage($this->makeCoverage($user, childPrimaryKeys: [10]));

        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertNull($this->graph->coverageFor($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function invalidate_model_class_removes_outgoing_edges_when_from_class_matches(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $this->graph->addEdge($this->makeEdge($user, $post));

        $this->graph->invalidateModelClass('App\\Models\\User');

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
    }

    #[Test]
    public function flush_clears_everything(): void
    {
        $user = $this->userIdentity();
        $post = $this->postIdentity();
        $this->graph->addEdge($this->makeEdge($user, $post));
        $this->graph->addCoverage($this->makeCoverage($user, childPrimaryKeys: [10]));

        $this->graph->flush();

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertNull($this->graph->coverageFor($user, 'posts'));
        $this->assertSame(0, $this->graph->edgeCount());
        $this->assertSame(0, $this->graph->coverageCount());
    }

    #[Test]
    public function max_edges_allows_storage_up_to_limit_then_flushes(): void
    {
        $graph = new IdentityGraph(maxEdges: 2);
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $userC = $this->userIdentity(3);
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);
        $postC = $this->postIdentity(30);

        $graph->addEdge($this->makeEdge($userA, $postA));
        $this->assertSame(1, $graph->edgeCount());

        $graph->addEdge($this->makeEdge($userB, $postB));
        $this->assertSame(2, $graph->edgeCount());

        $graph->addEdge($this->makeEdge($userC, $postC));
        $this->assertSame(0, $graph->edgeCount(), 'graph flushes when limit is reached');
    }

    #[Test]
    public function max_coverage_allows_storage_up_to_limit_then_flushes(): void
    {
        $graph = new IdentityGraph(maxCoverage: 2);
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $userC = $this->userIdentity(3);

        $graph->addCoverage($this->makeCoverage($userA, childPrimaryKeys: [10]));
        $this->assertSame(1, $graph->coverageCount());

        $graph->addCoverage($this->makeCoverage($userB, childPrimaryKeys: [20]));
        $this->assertSame(2, $graph->coverageCount());

        $graph->addCoverage($this->makeCoverage($userC, childPrimaryKeys: [30]));
        $this->assertSame(0, $graph->coverageCount(), 'graph flushes when limit is reached');
    }

    #[Test]
    public function invalidate_model_does_not_match_keys_that_share_a_pk_prefix(): void
    {
        $user1 = $this->userIdentity(1);
        $user10 = $this->userIdentity(10);
        $post = $this->postIdentity(99);

        $edge1 = $this->makeEdge($user1, $post);
        $edge10 = $this->makeEdge($user10, $post);
        $this->graph->addEdge($edge1);
        $this->graph->addEdge($edge10);

        $this->graph->invalidateModel($user1);

        $this->assertSame([], $this->graph->edgesFrom($user1, 'posts'));
        $this->assertSame([$edge10], $this->graph->edgesFrom($user10, 'posts'), 'id=10 must not be invalidated when id=1 is');
    }

    #[Test]
    public function invalidate_model_processes_all_buckets_for_that_model(): void
    {
        $user = $this->userIdentity();
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);
        // Two buckets keyed by (user, 'posts') and (user, 'comments').
        $this->graph->addEdge($this->makeEdge($user, $postA, 'posts'));
        $this->graph->addEdge($this->makeEdge($user, $postB, 'comments'));

        $this->graph->invalidateModel($user);

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertSame(
            [],
            $this->graph->edgesFrom($user, 'comments'),
            'all buckets for the invalidated model must be cleared, not just the first',
        );
    }

    #[Test]
    public function invalidate_model_keeps_other_edges_in_same_bucket(): void
    {
        $userA = $this->userIdentity();
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);

        $edgeToA = $this->makeEdge($userA, $postA);
        $edgeToB = $this->makeEdge($userA, $postB);
        $this->graph->addEdge($edgeToA);
        $this->graph->addEdge($edgeToB);

        $this->graph->invalidateModel($postA);

        $this->assertSame(
            [$edgeToB],
            $this->graph->edgesFrom($userA, 'posts'),
            'sibling edges to other models must survive when one target is invalidated',
        );
    }

    #[Test]
    public function invalidate_model_class_processes_all_buckets(): void
    {
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $postA = $this->postIdentity(10);
        $postB = $this->postIdentity(20);
        $this->graph->addEdge($this->makeEdge($userA, $postA));
        $this->graph->addEdge($this->makeEdge($userB, $postB));

        $this->graph->invalidateModelClass('App\\Models\\User');

        $this->assertSame([], $this->graph->edgesFrom($userA, 'posts'));
        $this->assertSame(
            [],
            $this->graph->edgesFrom($userB, 'posts'),
            'all buckets whose key includes the class must be cleared',
        );
    }

    #[Test]
    public function invalidate_model_class_keeps_other_edges_in_same_bucket(): void
    {
        $userA = $this->userIdentity();
        $postA = $this->postIdentity(10);
        $otherClass = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Tag',
            table: 'tags',
            primaryKeyName: 'id',
            primaryKeyValue: 5,
            scopeFingerprint: 'default',
        );
        $edgePost = $this->makeEdge($userA, $postA);
        $edgeTag = $this->makeEdge($userA, $otherClass);
        $this->graph->addEdge($edgePost);
        $this->graph->addEdge($edgeTag);

        $this->graph->invalidateModelClass('App\\Models\\Post');

        $this->assertSame(
            [$edgeTag],
            $this->graph->edgesFrom($userA, 'posts'),
            'sibling edges to other classes must survive in the same bucket',
        );
    }

    #[Test]
    public function invalidate_model_class_does_not_match_class_prefix_collision(): void
    {
        $user = $this->userIdentity();
        $tagAlpha = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\Tag',
            table: 'tags',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $tagAlphaExtended = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\TagExtra',
            table: 'tag_extras',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $edgeTag = $this->makeEdge($user, $tagAlpha, 'tag');
        $edgeTagExtra = $this->makeEdge($user, $tagAlphaExtended, 'tagExtra');
        $this->graph->addEdge($edgeTag);
        $this->graph->addEdge($edgeTagExtra);

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame([], $this->graph->edgesFrom($user, 'tag'));
        $this->assertSame(
            [$edgeTagExtra],
            $this->graph->edgesFrom($user, 'tagExtra'),
            'TagExtra must not be invalidated when Tag is',
        );
    }

    #[Test]
    public function invalidate_model_class_keeps_unrelated_coverage(): void
    {
        $userParent = $this->userIdentity();
        $unrelatedCoverage = $this->makeCoverage(
            $userParent,
            relationName: 'posts',
            relatedModelClass: 'App\\Models\\Post',
            childPrimaryKeys: [10],
        );
        $this->graph->addCoverage($unrelatedCoverage);

        $this->graph->invalidateModelClass('App\\Models\\Tag');

        $this->assertSame(
            $unrelatedCoverage,
            $this->graph->coverageFor($userParent, 'posts'),
            'coverage that does not involve the invalidated class must survive',
        );
    }

    #[Test]
    public function invalidate_model_does_not_match_keys_with_scope_fingerprint_prefix(): void
    {
        $userA = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'fp',
        );
        $userB = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\User',
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'fp-extra',
        );
        $post = $this->postIdentity();
        $edgeA = $this->makeEdge($userA, $post);
        $edgeB = $this->makeEdge($userB, $post);
        $this->graph->addEdge($edgeA);
        $this->graph->addEdge($edgeB);

        $this->graph->invalidateModel($userA);

        $this->assertSame([], $this->graph->edgesFrom($userA, 'posts'));
        $this->assertSame(
            [$edgeB],
            $this->graph->edgesFrom($userB, 'posts'),
            'a model whose key is a prefix of another (via scope fingerprint) must not invalidate the other',
        );
    }

    #[Test]
    public function invalidate_model_class_does_not_match_class_name_prefix_on_from_side(): void
    {
        $user = $this->userIdentity();
        $userExtra = new ModelIdentity(
            connection: 'default',
            modelClass: 'App\\Models\\UserExtra',
            table: 'user_extras',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
        );
        $post = $this->postIdentity();
        $edgeUser = $this->makeEdge($user, $post);
        $edgeUserExtra = $this->makeEdge($userExtra, $post);
        $this->graph->addEdge($edgeUser);
        $this->graph->addEdge($edgeUserExtra);

        $this->graph->invalidateModelClass('App\\Models\\User');

        $this->assertSame([], $this->graph->edgesFrom($user, 'posts'));
        $this->assertSame(
            [$edgeUserExtra],
            $this->graph->edgesFrom($userExtra, 'posts'),
            'a class whose name is a prefix of another must not invalidate the other class',
        );
    }

    #[Test]
    public function invalidate_model_class_removes_all_matching_coverage_entries(): void
    {
        $userA = $this->userIdentity(1);
        $userB = $this->userIdentity(2);
        $coverageA = $this->makeCoverage($userA, childPrimaryKeys: [10]);
        $coverageB = $this->makeCoverage($userB, childPrimaryKeys: [20]);
        $this->graph->addCoverage($coverageA);
        $this->graph->addCoverage($coverageB);

        $this->graph->invalidateModelClass('App\\Models\\User');

        $this->assertNull($this->graph->coverageFor($userA, 'posts'));
        $this->assertNull(
            $this->graph->coverageFor($userB, 'posts'),
            'all coverages whose key contains the class must be removed, not just the first',
        );
    }

    #[Test]
    public function invalidate_model_class_removes_coverage_when_parent_class_matches(): void
    {
        $userParent = $this->userIdentity();
        $coverage = $this->makeCoverage(
            $userParent,
            relationName: 'posts',
            relatedModelClass: 'App\\Models\\Post',
            childPrimaryKeys: [10],
        );
        $this->graph->addCoverage($coverage);

        $this->graph->invalidateModelClass('App\\Models\\User');

        $this->assertNull(
            $this->graph->coverageFor($userParent, 'posts'),
            'coverage whose parent class matches must be removed even if its key was already class-matched and continued past',
        );
    }

    #[Test]
    public function model_identity_key_includes_all_components(): void
    {
        $identity = new ModelIdentity(
            connection: 'pg',
            modelClass: 'A',
            table: 't',
            primaryKeyName: 'pk',
            primaryKeyValue: 42,
            scopeFingerprint: 'fp',
        );

        $this->assertSame('pg|A|t|pk|42|fp', $identity->key());
    }
}
