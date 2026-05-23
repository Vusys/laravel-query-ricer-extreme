<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateColumns;

final class PredicateColumnsTest extends TestCase
{
    #[Test]
    public function comparison_node_returns_its_column(): void
    {
        $this->assertSame(['active'], PredicateColumns::fromNode(new ComparisonNode('active', '=', true)));
    }

    #[Test]
    public function in_node_returns_its_column(): void
    {
        $this->assertSame(['status'], PredicateColumns::fromNode(new InNode('status', ['a', 'b'], false)));
    }

    #[Test]
    public function null_node_returns_its_column(): void
    {
        $this->assertSame(['deleted_at'], PredicateColumns::fromNode(new NullNode('deleted_at', false)));
    }

    #[Test]
    public function empty_and_node_returns_empty(): void
    {
        $this->assertSame([], PredicateColumns::fromNode(new AndNode([])));
    }

    #[Test]
    public function and_node_returns_all_child_columns(): void
    {
        $node = new AndNode([
            new ComparisonNode('active', '=', true),
            new ComparisonNode('role', '=', 'admin'),
            new NullNode('deleted_at', true),
        ]);

        $columns = PredicateColumns::fromNode($node);

        sort($columns);
        $this->assertSame(['active', 'deleted_at', 'role'], $columns);
    }

    #[Test]
    public function and_node_deduplicates_columns(): void
    {
        $node = new AndNode([
            new ComparisonNode('active', '=', true),
            new ComparisonNode('active', '=', false),
        ]);

        $this->assertSame(['active'], PredicateColumns::fromNode($node));
    }

    #[Test]
    public function nested_and_node_flattens_columns(): void
    {
        $inner = new AndNode([new ComparisonNode('role', '=', 'admin')]);
        $outer = new AndNode([new ComparisonNode('active', '=', true), $inner]);

        $columns = PredicateColumns::fromNode($outer);
        sort($columns);

        $this->assertSame(['active', 'role'], $columns);
    }
}
