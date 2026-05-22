<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

final readonly class ComparisonNode implements PredicateNode
{
    public function __construct(
        public string $column,
        public string $operator,
        public mixed $value,
    ) {}
}
