<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\MariaDbSemantics;
use Vusys\QueryRicerExtreme\Driver\MySqlSemantics;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

final class MariaDbSemanticsTest extends TestCase
{
    #[Test]
    public function is_a_mysql_semantics(): void
    {
        self::assertInstanceOf(MySqlSemantics::class, new MariaDbSemantics);
    }

    #[Test]
    public function strings_without_collation_are_unknown(): void
    {
        $s = new MariaDbSemantics;
        self::assertSame(EvaluationResult::Unknown, $s->compare('BRYAN', '=', 'bryan', ColumnSemantics::unknown()));
    }

    #[Test]
    public function case_insensitive_column_matches_mixed_case(): void
    {
        $s = new MariaDbSemantics;
        $col = new ColumnSemantics(ColumnType::String, 'utf8mb4_general_ci', StringComparisonMode::CaseInsensitive);
        self::assertSame(EvaluationResult::Match, $s->compare('BRYAN', '=', 'bryan', $col));
    }
}
