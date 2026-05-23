<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Coverage;

use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final readonly class CoverageEntry
{
    /**
     * @param  list<int|string>  $primaryKeys
     */
    public function __construct(
        public string $modelClass,
        public string $connection,
        public string $table,
        public string $scopeFingerprint,
        public PredicateNode $region,
        public ColumnSet $columns,
        public array $primaryKeys,
        public bool $complete,
        public int $version,
    ) {}
}
