<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

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

            if ($this->eagerLoad !== []) {
                $this->eagerLoadRelations([$cached]);
            }

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
        $extractor = new QueryPatternExtractor($this);

        // --- single primary-key lookup ---
        $primaryKeyId = $extractor->extractSinglePrimaryKeyLookup();

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
        $extraction = $extractor->extractBoundedKeySet();

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

                /** @var array<int, TModel> $ordered */
                $ordered = QueryPatternExtractor::mergeByInputOrder($memoryModels, [], $keySet);

                return $ordered;
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

            /** @var array<int, TModel> $ordered */
            $ordered = QueryPatternExtractor::mergeByInputOrder($memoryModels, $fetched, $keySet);

            return $ordered;
        }

        // --- unique-key positive hit ---
        $uniqueIndexes = $store->uniqueIndexesForModelClass($model::class);
        $uniqueExtraction = $extractor->extractUniqueKeyLookup($uniqueIndexes);

        if ($uniqueExtraction !== null) {
            [$uniqueKeyValues, $extraNodes] = $uniqueExtraction;

            $uniqueEntry = $store->findByUniqueKey(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                fingerprint: $fingerprint,
                equalityValues: $uniqueKeyValues,
            );

            if ($uniqueEntry !== null && $uniqueEntry->state === LifecycleState::Exists && $uniqueEntry->attributes->satisfies($columns)) {
                if ($extraNodes !== []) {
                    $evaluator = new PredicateEvaluator;
                    $evalResult = $evaluator->evaluate($uniqueEntry->attributes, new AndNode($extraNodes));

                    if ($evalResult === EvaluationResult::Reject) {
                        $store->capture(new Explanation(
                            type: PlanType::ReturnEmptyCollection,
                            modelClass: $model::class,
                            reason: 'unique-key-hit-predicate-rejected',
                            sqlExecuted: false,
                        ));

                        return [];
                    }

                    if ($evalResult === EvaluationResult::Match) {
                        $store->capture(new Explanation(
                            type: PlanType::ReturnCollectionFromMemory,
                            modelClass: $model::class,
                            reason: 'unique-key-positive-hit',
                            sqlExecuted: false,
                            memoryKeys: [$uniqueEntry->primaryKeyValue],
                        ));

                        /** @var TModel $cached */
                        $cached = $uniqueEntry->model;

                        return [$cached];
                    }
                    // Unknown → fall through to SQL below
                } else {
                    $store->capture(new Explanation(
                        type: PlanType::ReturnCollectionFromMemory,
                        modelClass: $model::class,
                        reason: 'unique-key-positive-hit',
                        sqlExecuted: false,
                        memoryKeys: [$uniqueEntry->primaryKeyValue],
                    ));

                    /** @var TModel $cached */
                    $cached = $uniqueEntry->model;

                    return [$cached];
                }
            }

            if ($extraNodes === [] && $store->isAbsentByUniqueKey(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                fingerprint: $fingerprint,
                equalityValues: $uniqueKeyValues,
            )) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnEmptyCollection,
                    modelClass: $model::class,
                    reason: 'unique-key-absence-tracked',
                    sqlExecuted: false,
                ));

                return [];
            }

            $models = parent::getModels($columns);

            $isFullSelect = $columns === ['*'];

            foreach ($models as $result) {
                if ($isFullSelect) {
                    $store->markAllColumnsKnown($result);
                }
            }

            if ($models === [] && $extraNodes === [] && (bool) config('query-ricer-extreme.absence_tracking.unique_key', true)) {
                $store->recordAbsentByUniqueKey(
                    connection: $connection,
                    modelClass: $model::class,
                    table: $model->getTable(),
                    fingerprint: $fingerprint,
                    equalityValues: $uniqueKeyValues,
                );
            }

            return $models;
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

    public function exists(): bool
    {
        if ($this->identityMapDisabled) {
            return parent::exists();
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::exists();
        }

        $uniqueIndexes = $store->uniqueIndexesForModelClass($this->getModel()::class);
        $extractor = new QueryPatternExtractor($this);
        $uniqueExtraction = $extractor->extractUniqueKeyLookup($uniqueIndexes);

        if ($uniqueExtraction === null) {
            return parent::exists();
        }

        [$uniqueKeyValues, $extraNodes] = $uniqueExtraction;

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = $model->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);

        $entry = $store->findByUniqueKey(
            connection: $connection,
            modelClass: $model::class,
            table: $model->getTable(),
            fingerprint: $fingerprint,
            equalityValues: $uniqueKeyValues,
        );

        if ($entry !== null && $entry->state === LifecycleState::Exists) {
            if ($extraNodes !== []) {
                $evaluator = new PredicateEvaluator;
                $evalResult = $evaluator->evaluate($entry->attributes, new AndNode($extraNodes));

                if ($evalResult === EvaluationResult::Reject) {
                    $store->capture(new Explanation(
                        type: PlanType::ReturnExistsFromMemory,
                        modelClass: $model::class,
                        reason: 'unique-key-hit-predicate-rejected',
                        sqlExecuted: false,
                    ));

                    return false;
                }

                if ($evalResult === EvaluationResult::Match) {
                    $store->capture(new Explanation(
                        type: PlanType::ReturnExistsFromMemory,
                        modelClass: $model::class,
                        reason: 'unique-key-positive-hit',
                        sqlExecuted: false,
                    ));

                    return true;
                }

                return parent::exists();
            }

            $store->capture(new Explanation(
                type: PlanType::ReturnExistsFromMemory,
                modelClass: $model::class,
                reason: 'unique-key-positive-hit',
                sqlExecuted: false,
            ));

            return true;
        }

        if ($extraNodes === [] && $store->isAbsentByUniqueKey(
            connection: $connection,
            modelClass: $model::class,
            table: $model->getTable(),
            fingerprint: $fingerprint,
            equalityValues: $uniqueKeyValues,
        )) {
            $store->capture(new Explanation(
                type: PlanType::ReturnExistsFromMemory,
                modelClass: $model::class,
                reason: 'unique-key-absence-tracked',
                sqlExecuted: false,
            ));

            return false;
        }

        $result = parent::exists();

        if (! $result && $extraNodes === [] && (bool) config('query-ricer-extreme.absence_tracking.unique_key', true)) {
            $store->recordAbsentByUniqueKey(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                fingerprint: $fingerprint,
                equalityValues: $uniqueKeyValues,
            );
        }

        return $result;
    }
}
