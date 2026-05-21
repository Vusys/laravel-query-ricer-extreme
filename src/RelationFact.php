<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Enums\RelationKind;

final readonly class RelationFact
{
    public function __construct(
        public string $name,
        public RelationKind $kind,
        public bool $loaded,
        public bool $complete,
        public mixed $value,
    ) {}
}
