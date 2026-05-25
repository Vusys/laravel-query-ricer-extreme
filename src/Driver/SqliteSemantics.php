<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

/**
 * SQLite uses BINARY collation by default for `=`, so byte-equality maps
 * exactly to database equality regardless of column metadata.
 *
 * LIKE has its own ASCII case-insensitive default but the evaluator does
 * not currently model LIKE.
 */
final class SqliteSemantics extends AbstractDriverSemantics
{
    #[\Override]
    protected function compareStrings(string $left, string $right, ColumnSemantics $column): bool
    {
        if ($column->stringComparison === StringComparisonMode::CaseInsensitive) {
            return strcasecmp($left, $right) === 0;
        }

        return $left === $right;
    }

    #[\Override]
    protected function orderStrings(string $left, string $right, ColumnSemantics $column): int
    {
        if ($column->stringComparison === StringComparisonMode::CaseInsensitive) {
            return strcasecmp($left, $right) <=> 0;
        }

        return strcmp($left, $right) <=> 0;
    }
}
