<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

/**
 * PostgreSQL uses case-sensitive equality and ordering by default. Byte-equality
 * maps to database equality unless the column is a citext extension type, which
 * the resolver must surface via ColumnSemantics::stringComparison = CaseInsensitive.
 *
 * Postgres orders NULLs LAST for ASC and FIRST for DESC by default.
 */
final class PostgresSemantics extends AbstractDriverSemantics
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
