<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ConservativeSemantics;
use Vusys\QueryRicerExtreme\Driver\NullOrdering;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

final class ConservativeSemanticsTest extends TestCase
{
    #[Test]
    public function equal_strings_match(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare('active', '=', 'active', ColumnSemantics::unknown()));
    }

    #[Test]
    public function different_strings_of_same_type_reject(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Reject, $s->compare('disabled', '=', 'active', ColumnSemantics::unknown()));
    }

    #[Test]
    public function cross_type_equality_returns_unknown(): void
    {
        $s = new ConservativeSemantics;
        // Conservative fallback refuses to guess about driver-specific
        // int↔bool coercion. Profiles that know the engine (Sqlite, MySql…)
        // handle this; the unknown-driver default does not.
        self::assertSame(EvaluationResult::Unknown, $s->compare(1, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare(0, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare(1, '!=', true, ColumnSemantics::unknown()));
    }

    #[Test]
    public function same_type_strict_equality_resolves(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(1, '=', 1, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(1, '=', 2, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Match, $s->compare(true, '=', true, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(true, '=', false, ColumnSemantics::unknown()));
    }

    #[Test]
    public function ordered_int_comparison_resolves(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Match, $s->compare(5, '>', 3, ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Reject, $s->compare(3, '>', 5, ColumnSemantics::unknown()));
    }

    #[Test]
    public function ordered_string_comparison_unknown(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare('a', '>', 'b', ColumnSemantics::unknown()));
    }

    #[Test]
    public function compare_for_order_numeric_resolves_string_returns_null(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(-1, $s->compareForOrder(1, 2, ColumnSemantics::unknown()));
        self::assertSame(0, $s->compareForOrder(2, 2, ColumnSemantics::unknown()));
        self::assertSame(1, $s->compareForOrder(2, 1, ColumnSemantics::unknown()));
        self::assertNull($s->compareForOrder('a', 'b', ColumnSemantics::unknown()));
    }

    #[Test]
    public function null_ordering_asc_is_last_desc_is_first(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(NullOrdering::NullsLast, $s->nullOrdering('asc'));
        self::assertSame(NullOrdering::NullsFirst, $s->nullOrdering('desc'));
        self::assertSame(NullOrdering::NullsFirst, $s->nullOrdering('DESC'));
    }

    #[Test]
    public function null_compared_to_anything_returns_unknown(): void
    {
        $s = new ConservativeSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare(null, '=', 'x', ColumnSemantics::unknown()));
        self::assertSame(EvaluationResult::Unknown, $s->compare('x', '=', null, ColumnSemantics::unknown()));
    }

    #[Test]
    public function compare_for_order_returns_null_when_one_side_is_non_numeric(): void
    {
        // Probes each half of `(!is_int && !is_float) || (!is_int && !is_float)` in compareForOrder
        // independently: dropping a `!` on either side leaves the other half to catch the bad case,
        // but only if the test exercises both halves with the asymmetric type pair.
        $s = new ConservativeSemantics;
        self::assertNull($s->compareForOrder('a', 5, ColumnSemantics::unknown()));
        self::assertNull($s->compareForOrder(5, 'a', ColumnSemantics::unknown()));
        self::assertNull($s->compareForOrder('a', 5.5, ColumnSemantics::unknown()));
        self::assertNull($s->compareForOrder(5.5, 'a', ColumnSemantics::unknown()));
    }
}
