<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\NullOrdering;
use Vusys\QueryRicerExtreme\Driver\SqliteSemantics;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

/**
 * Exercises behaviour inherited by every driver profile. Uses SqliteSemantics
 * as a concrete instance — its string semantics are well-defined under the
 * default ColumnSemantics so they do not interfere with the type-coercion
 * branches being tested here.
 */
final class AbstractDriverSemanticsTest extends TestCase
{
    #[Test]
    public function greater_than_only_match_strictly_above(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(2, '>', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(1, '>', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(0, '>', 1, ColumnSemantics::unknown()));
    }

    #[Test]
    public function greater_or_equal_matches_equal_value(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(2, '>=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(1, '>=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(0, '>=', 1, ColumnSemantics::unknown()));
    }

    #[Test]
    public function less_than_only_match_strictly_below(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(0, '<', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(1, '<', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(2, '<', 1, ColumnSemantics::unknown()));
    }

    #[Test]
    public function less_or_equal_matches_equal_value(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(0, '<=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(1, '<=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(2, '<=', 1, ColumnSemantics::unknown()));
    }

    #[Test]
    public function mixed_int_float_equality_resolves(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(1, '=', 1.0, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(1.0, '=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(1, '=', 2.0, ColumnSemantics::unknown()));
    }

    #[Test]
    public function int_to_string_returns_unknown(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare(1, '=', '1', ColumnSemantics::unknown()));
    }

    #[Test]
    public function bool_to_arbitrary_int_rejects(): void
    {
        $s = new SqliteSemantics;
        // Every supported driver coerces `bool = TRUE` to `bool = 1`, so 42 ≠ true.
        self::assertSame(EvaluationResult::Reject, $s->compare(42, '=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function bool_to_zero_float_coerces(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(0.0, '=', false, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(1.0, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(0.0, '=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function compare_for_order_resolves_int_float_mix(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(-1, $s->compareForOrder(1, 2.0, ColumnSemantics::unknown()));
        self::assertSame(0, $s->compareForOrder(1.5, 1.5, ColumnSemantics::unknown()));
        self::assertSame(1, $s->compareForOrder(2.0, 1, ColumnSemantics::unknown()));
    }

    #[Test]
    public function compare_for_order_string_returns_null_when_uncertain(): void
    {
        $s = new SqliteSemantics;
        $result = $s->compareForOrder(1, 'a', ColumnSemantics::unknown());
        self::assertNull($result);
    }

    #[Test]
    public function not_equal_inverts_match(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Reject, $s->compare(1, '!=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(1, '!=', 2, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(1, '<>', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(1, '<>', 2, ColumnSemantics::unknown()));
    }

    #[Test]
    public function not_equal_propagates_unknown_when_eq_unknown(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare(1, '!=', '1', ColumnSemantics::unknown()));
    }

    #[Test]
    public function ordered_against_non_numeric_pair_returns_unknown(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare(1, '>', 'x', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare('x', '<', 1, ColumnSemantics::unknown()));
    }

    #[Test]
    public function null_ordering_asc_is_last_desc_is_first(): void
    {
        $s = new SqliteSemantics;
        self::assertSame(NullOrdering::NullsLast, $s->nullOrdering('asc'));
        self::assertSame(NullOrdering::NullsLast, $s->nullOrdering('ASC'));
        self::assertSame(NullOrdering::NullsFirst, $s->nullOrdering('desc'));
        self::assertSame(NullOrdering::NullsFirst, $s->nullOrdering('DESC'));
    }
}
