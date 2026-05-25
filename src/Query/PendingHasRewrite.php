<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final readonly class PendingHasRewrite
{
    public function __construct(
        public string $relation,
        public bool $not,
        public PredicateNode $innerPredicate,
        public int $whereOffset,
    ) {}
}
