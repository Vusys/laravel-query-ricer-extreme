<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Knowledge\RelationKnowledge;

final class IdentityEntry
{
    public function __construct(
        public readonly string $connection,
        public readonly string $modelClass,
        public readonly string $table,
        public readonly string $primaryKeyName,
        public readonly int|string $primaryKeyValue,
        public readonly string $scopeFingerprint,
        public Model $model,
        public AttributeKnowledge $attributes,
        public RelationKnowledge $relations,
        public LifecycleState $state,
        public int $version,
    ) {}

    public function __clone(): void
    {
        $this->attributes = clone $this->attributes;
        $this->relations = clone $this->relations;
    }
}
