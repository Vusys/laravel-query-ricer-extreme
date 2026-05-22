<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;

final class AttributeFact
{
    public function __construct(
        public readonly string $column,
        public mixed $originalValue,
        public mixed $currentValue,
        public bool $isDirty,
        public FactConfidence $confidence,
        public FactSource $source,
    ) {}
}
