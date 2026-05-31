<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Graph\EdgeConfidence;
use Vusys\QueryRicerExtreme\Graph\EdgeSource;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Graph\RelationEdge;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Knowledge\ColumnBackfiller;
use Vusys\QueryRicerExtreme\Query\ModelMetadata;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Store\IdentityEntry;
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
                table: ModelMetadata::table($related),
                primaryKeyName: $related->getKeyName(),
                primaryKeyValue: $fkValue,
                fingerprint: $fingerprint,
            );
        } else {
            $entry = $store->findByUniqueKey(
                connection: $connection,
                modelClass: $related::class,
                table: ModelMetadata::table($related),
                fingerprint: $fingerprint,
                equalityValues: [$ownerKey => $fkValue],
            );
        }

        if ($entry !== null && $entry->state === LifecycleState::Exists) {
            $rawCols = $this->query->getQuery()->columns;
            $colList = null;

            if ($rawCols === null || $rawCols === []) {
                $colList = ['*'];
            } else {
                $stringCols = array_values(array_filter($rawCols, is_string(...)));
                if (count($stringCols) === count($rawCols)) {
                    $colList = $stringCols;
                }
                // else: SELECT contains raw expressions — cannot serve from cache
            }

            if ($colList !== null) {
                $hitMode = $this->resolveHit($entry, $colList);

                if ($hitMode !== null) {
                    $store->capture(new Explanation(
                        type: PlanType::ReturnBelongsToFromMemory,
                        modelClass: $related::class,
                        reason: $hitMode === 'backfilled' ? 'belongs-to-memory-hit-after-backfill' : 'belongs-to-memory-hit',
                        sqlExecuted: false,
                        memoryKeys: [$entry->primaryKeyValue],
                    ));

                    /** @var TRelatedModel $cached */
                    $cached = $entry->model;

                    $this->recordGraphEdge($cached);

                    return $cached;
                }
            }
        }

        $result = parent::getResults();

        if ($result instanceof Model) {
            $rawCols = $this->query->getQuery()->columns;
            if ($rawCols === null || $rawCols === ['*']) {
                resolve(IdentityMapStore::class)->markAllColumnsKnown($result);
            }

            $this->recordGraphEdge($result);
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     */
    private function resolveHit(IdentityEntry $entry, array $columns): ?string
    {
        if ($entry->attributes->satisfies($columns)) {
            return 'hit';
        }

        $backfiller = resolve(ColumnBackfiller::class);

        if (! $backfiller->isEnabled()) {
            return null;
        }

        $missing = $backfiller->missingColumns($entry, $columns);

        if ($missing === []) {
            return null;
        }

        return $backfiller->backfill($entry, $missing) ? 'backfilled' : null;
    }

    private function recordGraphEdge(Model $parent): void
    {
        if (! (bool) config('query-ricer-extreme.relation_graph.enabled', true)) {
            return;
        }

        $relationName = $this->getRelationName();

        if ($relationName === '') {
            return;
        }

        $childIdentity = ModelIdentity::fromModel($this->child);
        $parentIdentity = ModelIdentity::fromModel($parent);

        if (! $childIdentity instanceof ModelIdentity || ! $parentIdentity instanceof ModelIdentity) {
            return;
        }

        resolve(IdentityGraph::class)->addEdge(new RelationEdge(
            from: $childIdentity,
            relationName: $relationName,
            kind: RelationKind::BelongsTo,
            to: $parentIdentity,
            source: EdgeSource::ForeignKeyFact,
            confidence: EdgeConfidence::Certain,
            version: 1,
        ));
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
            : ModelMetadata::table($related).'.'.$deletedAt;

        return $type === 'Null'
            && $boolean === 'and'
            && is_string($column)
            && in_array($column, [$deletedAt, $qualifiedDeletedAt], true);
    }
}
