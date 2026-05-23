<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsTo<TRelatedModel, TDeclaringModel>
 */
final class MemoryBelongsTo extends BelongsTo
{
    #[\Override]
    public function getResults(): mixed
    {
        $fkValue = $this->getForeignKeyFrom($this->child);

        if ($fkValue === null) {
            /** @var TRelatedModel|null $default */
            $default = $this->getDefaultFor($this->parent);

            return $default;
        }

        if (! is_int($fkValue) && ! is_string($fkValue)) {
            return parent::getResults();
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::getResults();
        }

        if (! in_array(HasIdentityMap::class, class_uses_recursive($this->related::class), true)) {
            return parent::getResults();
        }

        if ($this->queryHasHazards()) {
            return parent::getResults();
        }

        if (! $this->hasOnlyBaseConstraints()) {
            return parent::getResults();
        }

        $ownerKey = $this->getOwnerKeyName();
        $related = $this->related;
        $connection = $related->getConnectionName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this->query);

        if ($ownerKey === $related->getKeyName()) {
            $entry = $store->find(
                connection: $connection,
                modelClass: $related::class,
                table: $related->getTable(),
                primaryKeyName: $related->getKeyName(),
                primaryKeyValue: $fkValue,
                fingerprint: $fingerprint,
            );
        } else {
            $entry = $store->findByUniqueKey(
                connection: $connection,
                modelClass: $related::class,
                table: $related->getTable(),
                fingerprint: $fingerprint,
                equalityValues: [$ownerKey => $fkValue],
            );
        }

        if ($entry !== null && $entry->state === LifecycleState::Exists) {
            $store->capture(new Explanation(
                type: PlanType::ReturnBelongsToFromMemory,
                modelClass: $related::class,
                reason: 'belongs-to-memory-hit',
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
        $qualifiedOwnerKey = $this->getQualifiedOwnerKeyName();
        $ownerKey = $this->getOwnerKeyName();

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $this->query->getQuery()->wheres;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $operator = $where['operator'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                $type === 'Basic'
                && is_string($column)
                && in_array($column, [$qualifiedOwnerKey, $ownerKey], true)
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
            : $related->getTable().'.'.$deletedAt;

        return $type === 'Null'
            && $boolean === 'and'
            && is_string($column)
            && in_array($column, [$deletedAt, $qualifiedDeletedAt], true);
    }
}
