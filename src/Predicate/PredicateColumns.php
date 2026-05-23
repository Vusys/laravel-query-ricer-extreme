<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

final class PredicateColumns
{
    /** @return list<string> */
    public static function fromNode(PredicateNode $node): array
    {
        return match (true) {
            $node instanceof AndNode => array_values(array_unique(
                array_merge([], ...array_map(self::fromNode(...), $node->children))
            )),
            $node instanceof ComparisonNode => [$node->column],
            $node instanceof InNode => [$node->column],
            $node instanceof NullNode => [$node->column],
            default => [],
        };
    }
}
