<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

/**
 * Per-driver comparison and ordering semantics.
 *
 * Implementations resolve a comparison the way the database would, or return
 * Unknown rather than guess. The principle is: resolve confidently or stay
 * Unknown — never silently downgrade to PHP loose equality.
 */
interface DriverSemantics
{
    /**
     * Compare two values for the given operator using the column's semantics.
     *
     * @param  '='|'!='|'<>'|'>'|'>='|'<'|'<='  $operator
     */
    public function compare(mixed $left, string $operator, mixed $right, ColumnSemantics $column): EvaluationResult;

    /**
     * Comparator for ORDER BY against the given column.
     *
     * Returns an int (negative / zero / positive) when the relative order is
     * known confidently, or null when the caller must fall through to SQL.
     */
    public function compareForOrder(mixed $left, mixed $right, ColumnSemantics $column): ?int;

    /**
     * Database-default NULL ordering for the given direction.
     */
    public function nullOrdering(string $direction): NullOrdering;
}
