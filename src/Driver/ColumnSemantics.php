<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

final readonly class ColumnSemantics
{
    public function __construct(
        public ColumnType $type = ColumnType::Unknown,
        public ?string $collation = null,
        public StringComparisonMode $stringComparison = StringComparisonMode::Unknown,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }
}
