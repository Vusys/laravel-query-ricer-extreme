<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Coverage\SubsetChecker;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;

final class SubsetCheckerTest extends TestCase
{
    private SubsetChecker $checker;

    #[\Override]
    protected function setUp(): void
    {
        $this->checker = new SubsetChecker;
    }

    // -------------------------------------------------------------------------
    // AndNode as recorded (supertype)
    // -------------------------------------------------------------------------

    #[Test]
    public function empty_and_is_tautology_and_everything_is_subset(): void
    {
        $recorded = new AndNode([]);
        $query = new ComparisonNode('col', '=', 1);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function recorded_and_requires_all_children_to_match(): void
    {
        // query: col = 1, recorded: AND(col = 1, other = 2) — query does NOT imply other = 2
        $recorded = new AndNode([
            new ComparisonNode('col', '=', 1),
            new ComparisonNode('other', '=', 2),
        ]);
        $query = new ComparisonNode('col', '=', 1);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function query_and_is_subset_if_any_child_is_subset(): void
    {
        // recorded: team_id = 10; query: AND(team_id = 10, active = true)
        // The AND narrows further, so it is still a subset of team_id = 10.
        $recorded = new ComparisonNode('team_id', '=', 10);
        $query = new AndNode([
            new ComparisonNode('team_id', '=', 10),
            new ComparisonNode('active', '=', true),
        ]);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function query_and_is_not_subset_when_no_child_matches(): void
    {
        $recorded = new ComparisonNode('col', '=', 99);
        $query = new AndNode([
            new ComparisonNode('other', '=', 1),
            new ComparisonNode('yet_another', '=', 2),
        ]);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function both_and_nodes_combine_rules(): void
    {
        // recorded: AND(team_id = 10); query: AND(team_id = 10, active = true)
        $recorded = new AndNode([new ComparisonNode('team_id', '=', 10)]);
        $query = new AndNode([
            new ComparisonNode('team_id', '=', 10),
            new ComparisonNode('active', '=', true),
        ]);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    // -------------------------------------------------------------------------
    // ComparisonNode vs ComparisonNode
    // -------------------------------------------------------------------------

    #[Test]
    public function equal_comparison_is_subset_of_itself(): void
    {
        $node = new ComparisonNode('col', '=', 42);

        $this->assertTrue($this->checker->isSubset($node, $node));
    }

    #[Test]
    public function different_column_is_not_subset(): void
    {
        $query = new ComparisonNode('a', '=', 1);
        $recorded = new ComparisonNode('b', '=', 1);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function different_operator_is_not_subset(): void
    {
        $query = new ComparisonNode('col', '=', 1);
        $recorded = new ComparisonNode('col', '!=', 1);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function different_value_is_not_subset(): void
    {
        $query = new ComparisonNode('col', '=', 1);
        $recorded = new ComparisonNode('col', '=', 2);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function chevron_operator_normalised_to_not_equal(): void
    {
        $query = new ComparisonNode('col', '<>', 5);
        $recorded = new ComparisonNode('col', '!=', 5);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function comparison_uses_loose_equality_for_value(): void
    {
        // int 1 == string '1' under loose equality
        $query = new ComparisonNode('col', '=', 1);
        $recorded = new ComparisonNode('col', '=', '1');

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    // -------------------------------------------------------------------------
    // ComparisonNode(=) vs InNode
    // -------------------------------------------------------------------------

    #[Test]
    public function equality_is_subset_of_in_containing_value(): void
    {
        $query = new ComparisonNode('col', '=', 3);
        $recorded = new InNode('col', [1, 2, 3, 4], false);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function equality_is_not_subset_of_in_not_containing_value(): void
    {
        $query = new ComparisonNode('col', '=', 9);
        $recorded = new InNode('col', [1, 2, 3], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function equality_is_not_subset_of_negated_in(): void
    {
        $query = new ComparisonNode('col', '=', 1);
        $recorded = new InNode('col', [1, 2, 3], true);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function equality_not_subset_of_in_different_column(): void
    {
        $query = new ComparisonNode('a', '=', 1);
        $recorded = new InNode('b', [1, 2, 3], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function non_equality_comparison_is_not_subset_of_in(): void
    {
        $query = new ComparisonNode('col', '!=', 1);
        $recorded = new InNode('col', [2, 3, 4], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    // -------------------------------------------------------------------------
    // InNode vs InNode
    // -------------------------------------------------------------------------

    #[Test]
    public function in_is_subset_of_larger_in(): void
    {
        $query = new InNode('col', [1, 2], false);
        $recorded = new InNode('col', [1, 2, 3], false);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function in_is_not_subset_when_value_missing(): void
    {
        $query = new InNode('col', [1, 4], false);
        $recorded = new InNode('col', [1, 2, 3], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function negated_query_in_is_not_subset(): void
    {
        $query = new InNode('col', [1, 2], true);
        $recorded = new InNode('col', [1, 2, 3], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function in_not_subset_of_negated_recorded(): void
    {
        $query = new InNode('col', [1, 2], false);
        $recorded = new InNode('col', [1, 2, 3], true);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function in_not_subset_of_in_different_column(): void
    {
        $query = new InNode('a', [1, 2], false);
        $recorded = new InNode('b', [1, 2], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    // -------------------------------------------------------------------------
    // InNode vs ComparisonNode(=)
    // -------------------------------------------------------------------------

    #[Test]
    public function single_element_in_is_subset_of_matching_equality(): void
    {
        $query = new InNode('col', [7], false);
        $recorded = new ComparisonNode('col', '=', 7);

        $this->assertTrue($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function multi_element_in_is_not_subset_of_equality(): void
    {
        $query = new InNode('col', [7, 8], false);
        $recorded = new ComparisonNode('col', '=', 7);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function single_in_not_subset_when_value_differs(): void
    {
        $query = new InNode('col', [7], false);
        $recorded = new ComparisonNode('col', '=', 99);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function negated_single_in_is_not_subset_of_equality(): void
    {
        $query = new InNode('col', [7], true);
        $recorded = new ComparisonNode('col', '=', 7);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function single_in_not_subset_of_non_equality_comparison(): void
    {
        $query = new InNode('col', [7], false);
        $recorded = new ComparisonNode('col', '!=', 7);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    // -------------------------------------------------------------------------
    // NullNode vs NullNode
    // -------------------------------------------------------------------------

    #[Test]
    public function is_null_is_subset_of_itself(): void
    {
        $node = new NullNode('col', false);

        $this->assertTrue($this->checker->isSubset($node, $node));
    }

    #[Test]
    public function is_not_null_is_subset_of_itself(): void
    {
        $node = new NullNode('col', true);

        $this->assertTrue($this->checker->isSubset($node, $node));
    }

    #[Test]
    public function is_null_is_not_subset_of_is_not_null(): void
    {
        $query = new NullNode('col', false);
        $recorded = new NullNode('col', true);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function null_node_not_subset_of_different_column(): void
    {
        $query = new NullNode('a', false);
        $recorded = new NullNode('b', false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function null_node_query_is_not_subset_of_in_node(): void
    {
        $query = new NullNode('col', false);
        $recorded = new InNode('col', [1, 2], false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    // -------------------------------------------------------------------------
    // Cross-type mismatches
    // -------------------------------------------------------------------------

    #[Test]
    public function comparison_node_is_not_subset_of_null_node(): void
    {
        $query = new ComparisonNode('col', '=', null);
        $recorded = new NullNode('col', false);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function null_node_is_not_subset_of_comparison_node(): void
    {
        $query = new NullNode('col', false);
        $recorded = new ComparisonNode('col', '=', null);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }

    #[Test]
    public function in_node_is_not_subset_of_null_node(): void
    {
        $query = new InNode('col', [1, 2], false);
        $recorded = new NullNode('col', true);

        $this->assertFalse($this->checker->isSubset($query, $recorded));
    }
}
