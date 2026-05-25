<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\SqliteSemantics;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

final class SqliteSemanticsTest extends TestCase
{
    #[Test]
    public function strings_use_binary_collation_by_default(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare('Bryan', '=', 'Bryan', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('BRYAN', '=', 'bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function case_insensitive_column_folds_case(): void
    {
        $s = new SqliteSemantics;
        $col = new ColumnSemantics(ColumnType::String, null, StringComparisonMode::CaseInsensitive);
        self::assertSame(EvaluationResult::Match, $s->compare('BRYAN', '=', 'bryan', $col));
    }

    #[Test]
    public function int_to_bool_resolves(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(1, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(0, '=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function uuid_byte_equality(): void
    {
        $s = new SqliteSemantics;
        $a = '550e8400-e29b-41d4-a716-446655440000';
        self::assertSame(EvaluationResult::Match, $s->compare($a, '=', $a, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare($a, '=', strtoupper($a), ColumnSemantics::unknown()));
    }

    #[Test]
    public function not_equal_inverts(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Reject, $s->compare('a', '!=', 'a', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare('a', '!=', 'b', ColumnSemantics::unknown()));
    }

    #[Test]
    public function string_ordering_with_unknown_collation_defaults_to_binary(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(-1, $s->compareForOrder('a', 'b', ColumnSemantics::unknown()));
        self::assertSame(0, $s->compareForOrder('a', 'a', ColumnSemantics::unknown()));
        self::assertSame(1, $s->compareForOrder('b', 'a', ColumnSemantics::unknown()));
    }

    #[Test]
    public function case_insensitive_column_orders_with_case_folding(): void
    {
        $s = new SqliteSemantics;
        $col = new ColumnSemantics(ColumnType::String, null, StringComparisonMode::CaseInsensitive);
        self::assertSame(-1, $s->compareForOrder('alice', 'BOB', $col));
        self::assertSame(0, $s->compareForOrder('Alice', 'alice', $col));
        self::assertSame(1, $s->compareForOrder('BOB', 'alice', $col));
    }
}
