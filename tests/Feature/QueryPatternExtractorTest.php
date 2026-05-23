<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Query\QueryPatternExtractor;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\Models\UuidUser;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class QueryPatternExtractorTest extends TestCase
{
    // -------------------------------------------------------------------------
    // extractSinglePrimaryKeyLookup
    // -------------------------------------------------------------------------

    #[Test]
    public function extract_single_pk_returns_int_for_basic_pk_equality(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('id', 5));

        $this->assertSame(5, $extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_string_for_string_pk(): void
    {
        // UuidUser has a string PK; the soft-delete global scope where is skipped as safe.
        $extractor = new QueryPatternExtractor(UuidUser::query()->where('id', 'abc-123'));

        $this->assertSame('abc-123', $extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_no_wheres(): void
    {
        $extractor = new QueryPatternExtractor(Post::query());

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_pk_appears_twice(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('id', 1)->where('id', 2));

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_operator_is_not_equals(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('id', '>', 1));

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_extra_non_safe_where_present(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('id', 1)->where('title', 'Hello'));

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_skips_soft_delete_global_scope_where(): void
    {
        // User has SoftDeletes, which adds deleted_at IS NULL as a global scope.
        // The extractor must treat that as a safe where and still return the PK.
        $extractor = new QueryPatternExtractor(User::query()->where('id', 1));

        $this->assertSame(1, $extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_value_is_not_int_or_string(): void
    {
        $builder = Post::query();
        $builder->getQuery()->wheres = [
            ['type' => 'Basic', 'column' => 'id', 'operator' => '=', 'value' => 1.5, 'boolean' => 'and'],
        ];
        $extractor = new QueryPatternExtractor($builder);

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_join_present(): void
    {
        $extractor = new QueryPatternExtractor(
            User::query()->join('posts', 'posts.user_id', '=', 'users.id')->where('users.id', 1)
        );

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_lock_present(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->where('id', 1)->lockForUpdate());

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_union_present(): void
    {
        $extractor = new QueryPatternExtractor(
            User::query()->where('id', 1)->union(User::query()->where('id', 2))
        );

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    #[Test]
    public function extract_single_pk_returns_null_when_group_by_present(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->where('id', 1)->groupBy('id'));

        $this->assertNull($extractor->extractSinglePrimaryKeyLookup());
    }

    // -------------------------------------------------------------------------
    // extractBoundedKeySet
    // -------------------------------------------------------------------------

    #[Test]
    public function extract_bounded_key_set_returns_keys_and_empty_extras_for_plain_where_in(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->whereIn('id', [1, 2, 3]));

        $result = $extractor->extractBoundedKeySet();
        $this->assertNotNull($result);
        [$keys, $extra] = $result;

        $this->assertSame([1, 2, 3], $keys);
        $this->assertSame([], $extra);
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_no_wheres(): void
    {
        $extractor = new QueryPatternExtractor(Post::query());

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_values_are_not_int_or_string(): void
    {
        $builder = Post::query();
        $builder->getQuery()->wheres = [
            ['type' => 'In', 'column' => 'id', 'values' => [1.5, 2.7], 'boolean' => 'and'],
        ];
        $extractor = new QueryPatternExtractor($builder);

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_no_where_in_on_pk(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('id', 1));

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_join_present(): void
    {
        $extractor = new QueryPatternExtractor(
            User::query()->join('posts', 'posts.user_id', '=', 'users.id')->whereKey([1, 2, 3])
        );

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_lock_present(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->whereKey([1, 2, 3])->lockForUpdate());

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_union_present(): void
    {
        $extractor = new QueryPatternExtractor(
            User::query()->whereKey([1, 2, 3])->union(User::query()->whereKey([4]))
        );

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_limit_set(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->whereKey([1, 2, 3])->limit(2));

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_positive_offset_set(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->whereKey([1, 2, 3])->offset(1));

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_returns_null_when_group_by_present(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->whereKey([1, 2, 3])->groupBy('id'));

        $this->assertNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_is_not_blocked_by_zero_offset(): void
    {
        $extractor = new QueryPatternExtractor(User::query()->whereKey([1, 2, 3])->offset(0));

        $this->assertNotNull($extractor->extractBoundedKeySet());
    }

    #[Test]
    public function extract_bounded_key_set_includes_extra_predicate_nodes_for_additional_wheres(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()->whereIn('id', [1, 2])->where('published', true)
        );

        $result = $extractor->extractBoundedKeySet();
        $this->assertNotNull($result);
        [$keys, $extra] = $result;

        $this->assertSame([1, 2], $keys);
        $this->assertCount(1, $extra);
        $this->assertInstanceOf(ComparisonNode::class, $extra[0]);
    }

    // -------------------------------------------------------------------------
    // extractUniqueKeyLookup
    // -------------------------------------------------------------------------

    #[Test]
    public function extract_unique_key_lookup_returns_null_when_indexes_list_is_empty(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('title', 'Hello'));

        $this->assertNull($extractor->extractUniqueKeyLookup([]));
    }

    #[Test]
    public function extract_unique_key_lookup_returns_null_when_query_has_join(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()
                ->where('title', 'Hello')
                ->join('users', 'users.id', '=', 'posts.user_id')
        );

        $this->assertNull($extractor->extractUniqueKeyLookup([['title']]));
    }

    #[Test]
    public function extract_unique_key_lookup_returns_values_when_unique_column_present(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('title', 'Hello'));

        $result = $extractor->extractUniqueKeyLookup([['title']]);
        $this->assertNotNull($result);
        [$values, $extra] = $result;

        $this->assertSame(['title' => 'Hello'], $values);
        $this->assertSame([], $extra);
    }

    #[Test]
    public function extract_unique_key_lookup_returns_null_when_unique_column_not_in_query(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('published', true));

        $this->assertNull($extractor->extractUniqueKeyLookup([['title']]));
    }

    #[Test]
    public function extract_unique_key_lookup_places_remaining_equality_wheres_in_extra_nodes(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()->where('title', 'Hello')->where('published', true)
        );

        $result = $extractor->extractUniqueKeyLookup([['title']]);
        $this->assertNotNull($result);
        [$values, $extra] = $result;

        $this->assertSame(['title' => 'Hello'], $values);
        $this->assertCount(1, $extra);
        $this->assertInstanceOf(ComparisonNode::class, $extra[0]);
    }

    // -------------------------------------------------------------------------
    // extractFullPredicate
    // -------------------------------------------------------------------------

    #[Test]
    public function extract_full_predicate_returns_empty_and_node_when_no_user_wheres(): void
    {
        // Post has no global scopes → wheres is empty → AndNode([])
        $extractor = new QueryPatternExtractor(Post::query());

        $result = $extractor->extractFullPredicate();

        $this->assertInstanceOf(AndNode::class, $result);
    }

    #[Test]
    public function extract_full_predicate_returns_single_comparison_node_for_one_where(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('published', true));

        $result = $extractor->extractFullPredicate();

        $this->assertInstanceOf(ComparisonNode::class, $result);
    }

    #[Test]
    public function extract_full_predicate_returns_and_node_for_multiple_wheres(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()->where('published', true)->where('title', 'Hello')
        );

        $result = $extractor->extractFullPredicate();

        $this->assertInstanceOf(AndNode::class, $result);
    }

    #[Test]
    public function extract_full_predicate_returns_null_when_or_boolean_present(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()->where('published', true)->orWhere('title', 'Hello')
        );

        $this->assertNull($extractor->extractFullPredicate());
    }

    #[Test]
    public function extract_full_predicate_skips_soft_delete_global_scope_where(): void
    {
        // User has SoftDeletes; the deleted_at IS NULL global scope where must be
        // skipped and not included in the returned predicate tree.
        $extractor = new QueryPatternExtractor(User::query()->where('active', true));

        $result = $extractor->extractFullPredicate();

        // Only the 'active' where should be included, not the deleted_at scope.
        $this->assertInstanceOf(ComparisonNode::class, $result);
    }

    // -------------------------------------------------------------------------
    // isSafeForCoverage
    // -------------------------------------------------------------------------

    #[Test]
    public function is_safe_for_coverage_returns_true_for_clean_query(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->where('published', true));

        $this->assertTrue($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_query_has_join(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()->join('users', 'users.id', '=', 'posts.user_id')
        );

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_query_has_lock(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->lockForUpdate());

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_query_is_distinct(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->distinct());

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_query_has_limit(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->limit(10));

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_offset_is_positive(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->offset(5));

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_true_when_offset_is_zero(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->offset(0));

        $this->assertTrue($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_query_has_group_by(): void
    {
        $extractor = new QueryPatternExtractor(Post::query()->groupBy('user_id'));

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    #[Test]
    public function is_safe_for_coverage_returns_false_when_query_has_having(): void
    {
        $extractor = new QueryPatternExtractor(
            Post::query()->groupBy('user_id')->having('user_id', '>', 0)
        );

        $this->assertFalse($extractor->isSafeForCoverage());
    }

    // -------------------------------------------------------------------------
    // mergeByInputOrder (static, no DB)
    // -------------------------------------------------------------------------

    #[Test]
    public function merge_by_input_order_preserves_key_order_across_memory_and_fetched(): void
    {
        $a = new Post;
        $a->id = 1;
        $b = new Post;
        $b->id = 2;
        $c = new Post;
        $c->id = 3;

        $result = QueryPatternExtractor::mergeByInputOrder(
            memoryModels: [$a, $c],
            fetchedModels: [$b],
            keyOrder: [1, 2, 3],
        );

        $this->assertSame([$a, $b, $c], $result);
    }

    #[Test]
    public function merge_by_input_order_omits_keys_absent_from_both_sets(): void
    {
        $a = new Post;
        $a->id = 1;

        $result = QueryPatternExtractor::mergeByInputOrder(
            memoryModels: [$a],
            fetchedModels: [],
            keyOrder: [1, 99],
        );

        $this->assertSame([$a], $result);
    }

    #[Test]
    public function merge_by_input_order_fetched_model_overwrites_memory_model_for_same_key(): void
    {
        $fromMemory = new Post;
        $fromMemory->id = 1;
        $fromFetch = new Post;
        $fromFetch->id = 1;

        $result = QueryPatternExtractor::mergeByInputOrder(
            memoryModels: [$fromMemory],
            fetchedModels: [$fromFetch],
            keyOrder: [1],
        );

        $this->assertSame([$fromFetch], $result);
    }
}
