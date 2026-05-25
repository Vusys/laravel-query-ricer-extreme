<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Illuminate\Database\Eloquent\Model;

/**
 * Resolves per-column semantics (type, collation, comparison mode) for use
 * by DriverSemantics implementations during predicate evaluation.
 */
interface ColumnSemanticsResolver
{
    public function for(Model $model, string $column): ColumnSemantics;
}
