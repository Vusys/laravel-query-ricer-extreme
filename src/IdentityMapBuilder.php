<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class IdentityMapBuilder extends Builder
{
    private bool $identityMapDisabled = false;

    public function withoutIdentityMap(): static
    {
        $clone = clone $this;
        $clone->identityMapDisabled = true;

        return $clone;
    }

    /**
     * @param  mixed  $id
     * @param  list<string>  $columns
     * @return TModel|Collection<int, TModel>|null
     */
    #[\Override]
    public function find($id, $columns = ['*']): mixed
    {
        if (is_array($id) || $id instanceof Arrayable) {
            /** @var Collection<int, TModel> */
            return $this->whereKey($id)->get($columns);
        }

        if ($this->identityMapDisabled) {
            return $this->whereKey($id)->first($columns);
        }

        if (! is_int($id) && ! is_string($id)) {
            return $this->whereKey($id)->first($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return $this->whereKey($id)->first($columns);
        }

        $connection = $this->getModel()->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $entry = $store->find(
            connection: $connection,
            modelClass: $model::class,
            table: $model->getTable(),
            primaryKeyName: $model->getKeyName(),
            primaryKeyValue: $id,
            fingerprint: $fingerprint,
        );

        if ($entry !== null && $entry->state === LifecycleState::Exists && $entry->attributes->satisfies($columns)) {
            $store->capture(new Explanation(
                type: PlanType::ReturnModelFromMemory,
                modelClass: $model::class,
                reason: 'exact-primary-key-hit',
                sqlExecuted: false,
                memoryKeys: [$id],
            ));
            /** @var TModel $cached */
            $cached = $entry->model;

            return $cached;
        }

        if ($store->isAbsent(
            connection: $connection,
            modelClass: $model::class,
            table: $model->getTable(),
            primaryKeyName: $model->getKeyName(),
            primaryKeyValue: $id,
            fingerprint: $fingerprint,
        )) {
            $store->capture(new Explanation(
                type: PlanType::ReturnNull,
                modelClass: $model::class,
                reason: 'primary-key-absence-tracked',
                sqlExecuted: false,
            ));

            return null;
        }

        $store->capture(new Explanation(
            type: PlanType::ExecuteNormally,
            modelClass: $model::class,
            reason: 'no-map-entry',
            sqlExecuted: true,
        ));

        $result = $this->whereKey($id)->first($columns);

        if ($result instanceof Model) {
            if ($columns === ['*']) {
                $store->markAllColumnsKnown($result);
            }
        } elseif ($result === null) {
            $store->recordAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $id,
                fingerprint: $fingerprint,
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     * @return array<int, TModel>
     */
    #[\Override]
    public function getModels($columns = ['*']): array
    {
        if ($this->identityMapDisabled) {
            return parent::getModels($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::getModels($columns);
        }

        $connection = $this->getModel()->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);

        // --- single primary-key lookup ---
        $primaryKeyId = $this->extractSinglePrimaryKeyLookup();

        if ($primaryKeyId !== null) {
            $entry = $store->find(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            );

            if ($entry !== null && $entry->state === LifecycleState::Exists && $entry->attributes->satisfies($columns)) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnCollectionFromMemory,
                    modelClass: $model::class,
                    reason: 'exact-primary-key-hit-via-where',
                    sqlExecuted: false,
                    memoryKeys: [$primaryKeyId],
                ));

                /** @var TModel $cached */
                $cached = $entry->model;

                return [$cached];
            }

            if ($store->isAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            )) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnEmptyCollection,
                    modelClass: $model::class,
                    reason: 'primary-key-absence-tracked',
                    sqlExecuted: false,
                ));

                return [];
            }
        }

        // --- bounded primary key-set lookup (with optional predicate pruning) ---
        $extraction = $this->extractBoundedKeySet();

        if ($extraction !== null) {
            [$keySet, $extraPredicateNodes] = $extraction;

            [$hits, , $unknownKeys] = $store->partitionKeySet(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                keys: $keySet,
                fingerprint: $fingerprint,
                columns: $columns,
            );

            $memoryModels = [];
            $memoryKeys = [];
            $rejectedKeys = [];
            $sqlKeys = $unknownKeys;

            if ($extraPredicateNodes !== []) {
                $evaluator = new PredicateEvaluator;
                $predicate = new AndNode($extraPredicateNodes);

                foreach ($hits as $hitKey => $hitEntry) {
                    $result = $evaluator->evaluate($hitEntry->attributes, $predicate);

                    if ($result === EvaluationResult::Match) {
                        /** @var TModel $hitModel */
                        $hitModel = $hitEntry->model;
                        $memoryModels[] = $hitModel;
                        $memoryKeys[] = $hitKey;
                    } elseif ($result === EvaluationResult::Unknown) {
                        $sqlKeys[] = $hitKey;
                    } else {
                        $rejectedKeys[] = $hitKey;
                    }
                }
            } else {
                foreach ($hits as $hitKey => $hitEntry) {
                    /** @var TModel $hitModel */
                    $hitModel = $hitEntry->model;
                    $memoryModels[] = $hitModel;
                    $memoryKeys[] = $hitKey;
                }
            }

            if ($sqlKeys === []) {
                $planType = $extraPredicateNodes !== []
                    ? PlanType::RewritePredicateAndMerge
                    : PlanType::ReturnCollectionFromMemory;

                $store->capture(new Explanation(
                    type: $planType,
                    modelClass: $model::class,
                    reason: $extraPredicateNodes !== [] ? 'predicate-pruned-all-known' : 'all-keys-known-or-absent',
                    sqlExecuted: false,
                    memoryKeys: $memoryKeys,
                    rejectedKeys: $rejectedKeys,
                ));

                return $this->mergeByInputOrder($memoryModels, [], $keySet);
            }

            // Rewrite: narrow to sql keys only (unknown + predicate-unknown hits).
            // The builder already carries all extra predicates; whereKey further restricts to sqlKeys.
            $rewriteBuilder = $this->withoutIdentityMap();
            $rewriteBuilder->whereKey($sqlKeys);

            /** @var array<int, TModel> $fetched */
            $fetched = $rewriteBuilder->getModels($columns);

            $isFullSelect = $columns === ['*'];

            $fetchedByKey = [];
            foreach ($fetched as $fetchedModel) {
                if ($isFullSelect) {
                    $store->markAllColumnsKnown($fetchedModel);
                }
                $k = $fetchedModel->getKey();
                if (is_int($k) || is_string($k)) {
                    $fetchedByKey[$k] = true;
                }
            }

            foreach ($unknownKeys as $unknownKey) {
                if (! isset($fetchedByKey[$unknownKey])) {
                    $store->recordAbsent(
                        connection: $connection,
                        modelClass: $model::class,
                        table: $model->getTable(),
                        primaryKeyName: $model->getKeyName(),
                        primaryKeyValue: $unknownKey,
                        fingerprint: $fingerprint,
                    );
                }
            }

            $planType = $extraPredicateNodes !== []
                ? PlanType::RewritePredicateAndMerge
                : PlanType::RewritePrimaryKeysAndMerge;

            $store->capture(new Explanation(
                type: $planType,
                modelClass: $model::class,
                reason: $extraPredicateNodes !== [] ? 'predicate-prune-and-rewrite' : 'key-set-rewrite',
                sqlExecuted: true,
                knownKeys: $memoryKeys,
                missingKeys: $sqlKeys,
                memoryKeys: $memoryKeys,
                rejectedKeys: $rejectedKeys,
            ));

            return $this->mergeByInputOrder($memoryModels, $fetched, $keySet);
        }

        // --- fallthrough: execute SQL normally ---
        $models = parent::getModels($columns);

        $isFullSelect = $columns === ['*'];

        foreach ($models as $result) {
            if ($isFullSelect) {
                $store->markAllColumnsKnown($result);
            }
        }

        if ($primaryKeyId !== null && $models === []) {
            $store->recordAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            );
        }

        return $models;
    }

    /**
     * @return array{list<int|string>, list<PredicateNode>}|null
     *                                                           Returns [keySet, extraPredicateNodes] where extraPredicateNodes is empty for pure key-set queries.
     */
    private function extractBoundedKeySet(): ?array
    {
        $query = $this->getQuery();

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $qualifiedKey = $model->getQualifiedKeyName();
        $unqualifiedKey = $model->getKeyName();

        $inWhere = null;
        $extraPredicateNodes = [];

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                in_array($type, ['In', 'InRaw'], true)
                && is_string($column)
                && in_array($column, [$qualifiedKey, $unqualifiedKey], true)
                && $boolean === 'and'
            ) {
                if ($inWhere !== null) {
                    return null;
                }
                $inWhere = $where;

                continue;
            }

            if ($this->isSafeGlobalScopeWhere($where)) {
                continue;
            }

            if ($boolean !== 'and') {
                return null;
            }

            $node = PredicateExtractor::fromWhere($where);

            if (! $node instanceof PredicateNode) {
                return null;
            }

            $extraPredicateNodes[] = $node;
        }

        if ($inWhere === null) {
            return null;
        }

        $values = $inWhere['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $keys = [];

        foreach ($values as $value) {
            if (! is_int($value) && ! is_string($value)) {
                return null;
            }

            $keys[] = $value;
        }

        return [$keys, $extraPredicateNodes];
    }

    /**
     * @param  array<int, TModel>  $memoryModels
     * @param  array<int, TModel>  $fetchedModels
     * @param  list<int|string>  $keyOrder
     * @return array<int, TModel>
     */
    private function mergeByInputOrder(array $memoryModels, array $fetchedModels, array $keyOrder): array
    {
        /** @var array<int|string, TModel> $byKey */
        $byKey = [];

        foreach ($memoryModels as $m) {
            $k = $m->getKey();
            if (is_int($k) || is_string($k)) {
                $byKey[$k] = $m;
            }
        }

        foreach ($fetchedModels as $m) {
            $k = $m->getKey();
            if (is_int($k) || is_string($k)) {
                $byKey[$k] = $m;
            }
        }

        $result = [];

        foreach ($keyOrder as $key) {
            if (isset($byKey[$key])) {
                $result[] = $byKey[$key];
            }
        }

        return $result;
    }

    private function extractSinglePrimaryKeyLookup(): int|string|null
    {
        $query = $this->getQuery();

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $qualifiedKey = $model->getQualifiedKeyName();
        $unqualifiedKey = $model->getKeyName();

        $pkWhere = null;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $operator = $where['operator'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                $type === 'Basic'
                && is_string($column)
                && in_array($column, [$qualifiedKey, $unqualifiedKey], true)
                && $operator === '='
                && $boolean === 'and'
            ) {
                if ($pkWhere !== null) {
                    return null;
                }

                $pkWhere = $where;

                continue;
            }

            if (! $this->isSafeGlobalScopeWhere($where)) {
                return null;
            }
        }

        if ($pkWhere === null) {
            return null;
        }

        $value = $pkWhere['value'] ?? null;

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $where */
    private function isSafeGlobalScopeWhere(array $where): bool
    {
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;

        return $type === 'Null' && is_string($column) && str_ends_with($column, 'deleted_at');
    }
}
