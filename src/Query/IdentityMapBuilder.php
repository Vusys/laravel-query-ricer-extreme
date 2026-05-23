<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Coverage\CoverageEntry;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;
use Vusys\QueryRicerExtreme\Store\IdentityEntry;
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

        // --- coverage registry check ---
        $coveredModels = $this->getModelsFromCoverage($columns, $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            $store->capture(new Explanation(
                type: PlanType::ReturnCollectionFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return $coveredModels;
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

        // --- record coverage after SQL ---
        $this->tryRecordCoverage($models, $columns, $model::class, $connection, $fingerprint, $extractor);

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
            // --- coverage check for exists ---
            return $this->existsFromCoverageOrSql($store, $extractor);
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

    public function count(string $columns = '*'): int
    {
        if ($this->identityMapDisabled) {
            return parent::count($columns);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::count($columns);
        }

        if ($columns !== '*') {
            return parent::count($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = $model->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = new QueryPatternExtractor($this);

        // For count(*) we don't need output columns, pass [] to skip satisfies check.
        $coveredModels = $this->getModelsFromCoverage([], $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            $store->capture(new Explanation(
                type: PlanType::ReturnCountFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return count($coveredModels);
        }

        return parent::count($columns);
    }

    /**
     * @return SupportCollection<int|string, mixed>
     */
    #[\Override]
    public function pluck($column, $key = null): SupportCollection
    {
        if ($this->identityMapDisabled) {
            return parent::pluck($column, $key);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::pluck($column, $key);
        }

        if (! is_string($column)) {
            return parent::pluck($column, $key);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = $model->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = new QueryPatternExtractor($this);

        $neededColumns = is_string($key) ? [$column, $key] : [$column];

        $coveredModels = $this->getModelsFromCoverage($neededColumns, $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            $store->capture(new Explanation(
                type: PlanType::ReturnPluckFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            $eloquentCollection = new Collection($coveredModels);

            return $eloquentCollection->pluck($column, $key);
        }

        return parent::pluck($column, $key);
    }

    /**
     * @param  list<string>|string  $columns
     * @return TModel|null
     */
    #[\Override]
    public function first($columns = ['*']): mixed
    {
        if ($this->identityMapDisabled) {
            return parent::first($columns);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::first($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = $model->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = new QueryPatternExtractor($this);

        $resolvedColumns = is_array($columns) ? $columns : [$columns];

        $coveredModels = $this->getModelsFromCoverage($resolvedColumns, $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            $sorted = $this->sortForFirst($coveredModels);

            if ($sorted !== null) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnFirstFromCoverage,
                    modelClass: $model::class,
                    reason: 'coverage-subset-hit',
                    sqlExecuted: false,
                ));

                return $sorted;
            }
        }

        return parent::first($columns);
    }

    #[\Override]
    public function update(array $values): mixed
    {
        resolve(CoverageRegistry::class)->flushModelClass($this->getModel()::class);

        return parent::update($values);
    }

    #[\Override]
    public function delete(): mixed
    {
        resolve(CoverageRegistry::class)->flushModelClass($this->getModel()::class);

        return parent::delete();
    }

    #[\Override]
    public function forceDelete(): mixed
    {
        resolve(CoverageRegistry::class)->flushModelClass($this->getModel()::class);

        return parent::forceDelete();
    }

    /**
     * Try to answer a query from the coverage registry.
     *
     * Returns the filtered model array when coverage can serve the query, or
     * null when the query must fall through to SQL.
     *
     * @param  list<string>  $columns  Output columns required ([] = no output columns needed)
     * @return array<int, TModel>|null
     */
    private function getModelsFromCoverage(
        array $columns,
        IdentityMapStore $store,
        string $connection,
        string $fingerprint,
        QueryPatternExtractor $extractor,
    ): ?array {
        if (! $extractor->isSafeForCoverage()) {
            return null;
        }

        $region = $extractor->extractFullPredicate();

        if (! $region instanceof PredicateNode) {
            return null;
        }

        $registry = resolve(CoverageRegistry::class);

        /** @var TModel $model */
        $model = $this->getModel();

        $entry = $registry->findCovering(
            modelClass: $model::class,
            connection: $connection,
            table: $model->getTable(),
            scopeFingerprint: $fingerprint,
            queryRegion: $region,
        );

        if ($entry === null) {
            return null;
        }

        if ($columns !== [] && ! $entry->columns->covers($columns)) {
            return null;
        }

        $pkName = $model->getKeyName();
        $evaluator = new PredicateEvaluator;
        $result = [];

        foreach ($entry->primaryKeys as $pk) {
            $mapEntry = $store->find($connection, $model::class, $model->getTable(), $pkName, $pk, $fingerprint);

            if (! $mapEntry instanceof IdentityEntry || $mapEntry->state !== LifecycleState::Exists) {
                return null;
            }

            if ($columns !== [] && ! $mapEntry->attributes->satisfies($columns)) {
                return null;
            }

            $evalResult = $evaluator->evaluate($mapEntry->attributes, $region);

            if ($evalResult === EvaluationResult::Unknown) {
                return null;
            }

            if ($evalResult === EvaluationResult::Match) {
                /** @var TModel $typed */
                $typed = $mapEntry->model;
                $result[] = $typed;
            }
        }

        return $result;
    }

    /**
     * Record a coverage entry after a SQL query executed successfully.
     *
     * @param  array<int, TModel>  $models
     * @param  list<string>  $columns
     */
    private function tryRecordCoverage(
        array $models,
        array $columns,
        string $modelClass,
        string $connection,
        string $fingerprint,
        QueryPatternExtractor $extractor,
    ): void {
        if (! $extractor->isSafeForCoverage()) {
            return;
        }

        $region = $extractor->extractFullPredicate();

        if (! $region instanceof PredicateNode) {
            return;
        }

        $primaryKeys = [];

        foreach ($models as $m) {
            $pk = $m->getKey();

            if (! is_int($pk) && ! is_string($pk)) {
                return;
            }

            $primaryKeys[] = $pk;
        }

        $registry = resolve(CoverageRegistry::class);
        $registry->record(new CoverageEntry(
            modelClass: $modelClass,
            connection: $connection,
            table: $this->getModel()->getTable(),
            scopeFingerprint: $fingerprint,
            region: $region,
            columns: new ColumnSet($columns),
            primaryKeys: $primaryKeys,
            complete: true,
            version: 1,
        ));
    }

    /**
     * Handle the exists() path for coverage + SQL fallthrough when unique-key check did not match.
     */
    private function existsFromCoverageOrSql(IdentityMapStore $store, QueryPatternExtractor $extractor): bool
    {
        /** @var TModel $model */
        $model = $this->getModel();
        $connection = $model->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);

        $coveredModels = $this->getModelsFromCoverage([], $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            $store->capture(new Explanation(
                type: PlanType::ReturnExistsFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return $coveredModels !== [];
        }

        return parent::exists();
    }

    /**
     * Sort covered models for first() using the query's ORDER BY clauses.
     *
     * Returns the first model when sorting is safe, or null when the query must
     * fall through to SQL (no ORDER BY, unsafe sort columns, or multiple results).
     *
     * @param  array<int, TModel>  $models
     * @return TModel|null
     */
    private function sortForFirst(array $models): mixed
    {
        if ($models === []) {
            return null;
        }

        if (count($models) === 1) {
            return $models[0];
        }

        /** @var array<int, array<string, mixed>> $orders */
        $orders = $this->getQuery()->orders ?? [];

        if ($orders === []) {
            return null;
        }

        foreach ($orders as $order) {
            if (! isset($order['column']) || ! is_string($order['column'])) {
                return null;
            }
        }

        // Verify all sort column values are numeric across all models (int or float only).
        foreach ($orders as $order) {
            $col = $order['column'];
            assert(is_string($col));

            foreach ($models as $m) {
                $val = $m->getAttribute($col);

                if (! is_int($val) && ! is_float($val)) {
                    return null;
                }
            }
        }

        usort($models, function (Model $a, Model $b) use ($orders): int {
            foreach ($orders as $order) {
                $col = $order['column'];
                assert(is_string($col));
                $va = $a->getAttribute($col);
                $vb = $b->getAttribute($col);

                if ($va === $vb) {
                    continue;
                }

                $cmp = $va < $vb ? -1 : 1;
                $rawDir = $order['direction'] ?? null;
                $direction = is_string($rawDir) && strtolower($rawDir) === 'desc' ? 'desc' : 'asc';

                return $direction === 'asc' ? $cmp : -$cmp;
            }

            return 0;
        });

        return $models[0];
    }
}
