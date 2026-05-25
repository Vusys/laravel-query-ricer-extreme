<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\NullColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;

final class NullColumnSemanticsResolverTest extends TestCase
{
    #[Test]
    public function every_lookup_returns_unknown_semantics(): void
    {
        $resolver = new NullColumnSemanticsResolver;
        $model = new class extends Model {};

        $semantics = $resolver->for($model, 'whatever_column');

        self::assertSame(ColumnType::Unknown, $semantics->type);
        self::assertNull($semantics->collation);
        self::assertSame(StringComparisonMode::Unknown, $semantics->stringComparison);
    }
}
