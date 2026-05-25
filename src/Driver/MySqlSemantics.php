<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

/**
 * MySQL's default collations (utf8mb4_0900_ai_ci, utf8mb4_unicode_ci, etc.)
 * are case- and accent-insensitive. We can only resolve a string comparison
 * confidently when ColumnSemantics tells us the collation; otherwise we
 * return Unknown rather than guess.
 */
class MySqlSemantics extends AbstractDriverSemantics
{
    #[\Override]
    protected function compareStrings(string $left, string $right, ColumnSemantics $column): ?bool
    {
        if ($left === $right) {
            return true;
        }

        return match ($column->stringComparison) {
            StringComparisonMode::CaseSensitive => false,
            StringComparisonMode::CaseInsensitive => strcasecmp($left, $right) === 0,
            StringComparisonMode::Unknown => null,
        };
    }

    #[\Override]
    protected function orderStrings(string $left, string $right, ColumnSemantics $column): ?int
    {
        if ($left === $right) {
            return 0;
        }

        return match ($column->stringComparison) {
            StringComparisonMode::CaseSensitive => strcmp($left, $right) <=> 0,
            StringComparisonMode::CaseInsensitive => strcasecmp($left, $right) <=> 0,
            StringComparisonMode::Unknown => null,
        };
    }
}
