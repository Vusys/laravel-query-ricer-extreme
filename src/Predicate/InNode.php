<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

final readonly class InNode implements PredicateNode
{
    /** @param list<mixed> $values */
    public function __construct(
        public string $column,
        public array $values,
        public bool $negated,
    ) {}
}
