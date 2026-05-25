<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\PostgresSemantics;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

final class PostgresSemanticsTest extends TestCase
{
    #[Test]
    public function strings_are_case_sensitive_by_default(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare('Bryan', '=', 'Bryan', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('BRYAN', '=', 'bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function citext_column_folds_case(): void
    {
        $s = new PostgresSemantics;
        $col = new ColumnSemantics(ColumnType::String, null, StringComparisonMode::CaseInsensitive);
        self::assertSame(EvaluationResult::Match, $s->compare('BRYAN', '=', 'bryan', $col));
    }

    #[Test]
    public function bool_to_bool(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(true, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(true, '=', false, ColumnSemantics::unknown()));
    }

    #[Test]
    public function int_to_int_ordering(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(5, '>=', 5, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(4, '>', 5, ColumnSemantics::unknown()));
    }

    #[Test]
    public function string_ordering_with_case_sensitive_default(): void
    {
        $s = new PostgresSemantics;
        self::assertSame(-1, $s->compareForOrder('alice', 'bob', ColumnSemantics::unknown()));
        self::assertSame(0, $s->compareForOrder('alice', 'alice', ColumnSemantics::unknown()));
        self::assertSame(1, $s->compareForOrder('bob', 'alice', ColumnSemantics::unknown()));
    }

    #[Test]
    public function citext_ordering_folds_case(): void
    {
        $s = new PostgresSemantics;
        $col = new ColumnSemantics(ColumnType::String, null, StringComparisonMode::CaseInsensitive);
        self::assertSame(-1, $s->compareForOrder('alice', 'BOB', $col));
        self::assertSame(0, $s->compareForOrder('Alice', 'alice', $col));
        self::assertSame(1, $s->compareForOrder('BOB', 'alice', $col));
    }

    #[Test]
    public function case_sensitive_strings_match_only_byte_identical(): void
    {
        $s = new PostgresSemantics;
        // Direct evidence that byte-identical resolves Match; byte-different
        // resolves Reject (kills the `$left === $right` → `!==` mutation).
        self::assertSame(EvaluationResult::Match, $s->compare('bryan', '=', 'bryan', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('bryan', '=', 'BRYAN', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare('bryan', '=', 'alice', ColumnSemantics::unknown()));
    }
}
