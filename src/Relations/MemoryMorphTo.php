<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Query\ModelMetadata;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphTo<TRelatedModel, TDeclaringModel>
 */
final class MemoryMorphTo extends MorphTo
{
    #[\Override]
    public function getResults(): mixed
    {
        $morphTypeValue = $this->parent->{$this->morphType};

        if (! is_string($morphTypeValue) || $morphTypeValue === '') {
            /** @var TRelatedModel|null $default */
            $default = $this->getDefaultFor($this->parent);

            return $default;
        }

        $fkValue = $this->getForeignKeyFrom($this->child);

        if ($fkValue === null) {
            /** @var TRelatedModel|null $default */
            $default = $this->getDefaultFor($this->parent);

            return $default;
        }

        if (! is_int($fkValue) && ! is_string($fkValue)) {
            return parent::getResults();
        }

        /** @var class-string<Model> $resolvedClass */
        $resolvedClass = Relation::getMorphedModel($morphTypeValue) ?? $morphTypeValue;

        if (! class_exists($resolvedClass)) {
            return parent::getResults();
        }

        if (! in_array(HasIdentityMap::class, class_uses_recursive($resolvedClass), true)) {
            return parent::getResults();
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::getResults();
        }

        if ($this->queryHasHazards()) {
            return parent::getResults();
        }

        if (! $this->hasOnlyBaseConstraints()) {
            return parent::getResults();
        }

        /** @var TRelatedModel $relatedInstance */
        $relatedInstance = new $resolvedClass;
        $connection = $relatedInstance->getConnectionName() ?? $this->query->getModel()->getConnectionName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this->query);
        $ownerKey = $this->getOwnerKeyName();

        if ($ownerKey === $relatedInstance->getKeyName()) {
            $entry = $store->find(
                connection: $connection,
                modelClass: $resolvedClass,
                table: ModelMetadata::table($relatedInstance),
                primaryKeyName: $relatedInstance->getKeyName(),
                primaryKeyValue: $fkValue,
                fingerprint: $fingerprint,
            );
        } else {
            $entry = $store->findByUniqueKey(
                connection: $connection,
                modelClass: $resolvedClass,
                table: ModelMetadata::table($relatedInstance),
                fingerprint: $fingerprint,
                equalityValues: [$ownerKey => $fkValue],
            );
        }

        if ($entry !== null && $entry->state === LifecycleState::Exists) {
            $store->capture(new Explanation(
                type: PlanType::ReturnMorphToFromMemory,
                modelClass: $resolvedClass,
                reason: 'morph-to-memory-hit',
                sqlExecuted: false,
                memoryKeys: [$entry->primaryKeyValue],
            ));

            /** @var TRelatedModel $cached */
            $cached = $entry->model;

            return $cached;
        }

        return parent::getResults();
    }

    private function queryHasHazards(): bool
    {
        $query = $this->query->getQuery();

        return ($query->joins !== null && $query->joins !== [])
            || ($query->unions !== null && $query->unions !== [])
            || ($query->groups !== null && $query->groups !== [])
            || ($query->havings !== null && $query->havings !== [])
            || $query->lock !== null;
    }

    private function hasOnlyBaseConstraints(): bool
    {
        $query = $this->query->getQuery();
        $qualifiedOwnerKey = $this->getQualifiedOwnerKeyName();
        $unqualifiedOwnerKey = $this->ownerKey;

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $operator = $where['operator'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                $type === 'Basic'
                && is_string($column)
                && in_array($column, [$qualifiedOwnerKey, $unqualifiedOwnerKey], true)
                && $operator === '='
                && $boolean === 'and'
            ) {
                continue;
            }

            if ($this->isSafeGlobalScopeWhere($where)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $where */
    private function isSafeGlobalScopeWhere(array $where): bool
    {
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;
        $boolean = $where['boolean'] ?? null;

        $related = $this->related;
        $deletedAt = method_exists($related, 'getDeletedAtColumn')
            ? $related->getDeletedAtColumn()
            : 'deleted_at';
        $qualifiedDeletedAt = method_exists($related, 'getQualifiedDeletedAtColumn')
            ? $related->getQualifiedDeletedAtColumn()
            : ModelMetadata::table($related).'.'.$deletedAt;

        return $type === 'Null'
            && $boolean === 'and'
            && is_string($column)
            && in_array($column, [$deletedAt, $qualifiedDeletedAt], true);
    }
}
