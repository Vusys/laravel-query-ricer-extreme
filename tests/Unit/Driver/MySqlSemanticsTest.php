<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\MySqlSemantics;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

final class MySqlSemanticsTest extends TestCase
{
    #[Test]
    public function strings_without_collation_are_unknown(): void
    {
        $s = new MySqlSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare('BRYAN', '=', 'bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function byte_identical_strings_match_without_collation(): void
    {
        $s = new MySqlSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare('Bryan', '=', 'Bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function case_insensitive_column_matches_mixed_case(): void
    {
        $s = new MySqlSemantics;
        $col = new ColumnSemantics(ColumnType::String, 'utf8mb4_unicode_ci', StringComparisonMode::CaseInsensitive);
        self::assertSame(EvaluationResult::Match, $s->compare('BRYAN', '=', 'bryan', $col));
    }

    #[Test]
    public function case_sensitive_column_rejects_mixed_case(): void
    {
        $s = new MySqlSemantics;
        $col = new ColumnSemantics(ColumnType::String, 'utf8mb4_bin', StringComparisonMode::CaseSensitive);
        self::assertSame(EvaluationResult::Reject, $s->compare('BRYAN', '=', 'bryan', $col));
    }

    #[Test]
    public function tinyint_one_matches_bool_true(): void
    {
        $s = new MySqlSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(1, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(0, '=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function not_equal_propagates_unknown(): void
    {
        $s = new MySqlSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare('BRYAN', '!=', 'bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function string_ordering_unknown_without_collation(): void
    {
        $s = new MySqlSemantics;
        self::assertNull($s->compareForOrder('alice', 'bob', ColumnSemantics::unknown()));
    }

    #[Test]
    public function case_sensitive_ordering_uses_byte_compare(): void
    {
        $s = new MySqlSemantics;
        $col = new ColumnSemantics(ColumnType::String, 'utf8mb4_bin', StringComparisonMode::CaseSensitive);
        self::assertSame(-1, $s->compareForOrder('alice', 'bob', $col));
        self::assertSame(0, $s->compareForOrder('alice', 'alice', $col));
        self::assertSame(1, $s->compareForOrder('bob', 'alice', $col));
    }

    #[Test]
    public function case_insensitive_ordering_folds_case(): void
    {
        $s = new MySqlSemantics;
        $col = new ColumnSemantics(ColumnType::String, 'utf8mb4_unicode_ci', StringComparisonMode::CaseInsensitive);
        self::assertSame(-1, $s->compareForOrder('alice', 'BOB', $col));
        self::assertSame(0, $s->compareForOrder('Alice', 'alice', $col));
        self::assertSame(1, $s->compareForOrder('BOB', 'alice', $col));
    }
}
