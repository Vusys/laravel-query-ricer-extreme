<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection as SupportCollection;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Coverage\CoverageEntry;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Graph\RelationCoverage;
use Vusys\QueryRicerExtreme\Knowledge\ColumnBackfiller;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;
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

    /** @var list<PendingHasRewrite> */
    private array $pendingHasRewrites = [];

    public function withoutIdentityMap(): static
    {
        $clone = clone $this;
        $clone->identityMapDisabled = true;

        return $clone;
    }

    #[\Override]
    public function whereHas($relation, ?Closure $callback = null, $operator = '>=', $count = 1)
    {
        if (! is_string($relation) || str_contains($relation, '.') || $operator !== '>=' || $count !== 1) {
            return parent::whereHas($relation, $callback, $operator, $count);
        }

        $before = count($this->getQuery()->wheres);
        $result = parent::whereHas($relation, $callback, $operator, $count);
        $this->maybeRecordHasRewrite($relation, false, $before);

        return $result;
    }

    #[\Override]
    public function whereDoesntHave($relation, ?Closure $callback = null)
    {
        if (! is_string($relation) || str_contains($relation, '.')) {
            return parent::whereDoesntHave($relation, $callback);
        }

        $before = count($this->getQuery()->wheres);
        $result = parent::whereDoesntHave($relation, $callback);
        $this->maybeRecordHasRewrite($relation, true, $before);

        return $result;
    }

    #[\Override]
    public function __clone()
    {
        parent::__clone();
        // pendingHasRewrites is a list of value objects — shallow copy is fine.
        // We keep the rewrites so cloned builders (e.g. via withoutIdentityMap) still
        // know which `wheres` indexes came from whereHas, preserving SQL fallthrough.
    }

    private function maybeRecordHasRewrite(string $relation, bool $not, int $beforeOffset): void
    {
        $wheres = $this->getQuery()->wheres;

        if (count($wheres) !== $beforeOffset + 1) {
            return;
        }

        $where = $wheres[$beforeOffset];
        $expectedType = $not ? 'NotExists' : 'Exists';

        if (($where['type'] ?? null) !== $expectedType || ($where['boolean'] ?? null) !== 'and') {
            return;
        }

        $subQuery = $where['query'] ?? null;

        if (! $subQuery instanceof QueryBuilder) {
            return;
        }

        $model = $this->getModel();

        if (! method_exists($model, $relation)) {
            return;
        }

        $relationObj = $model->{$relation}();

        if (! is_object($relationObj) || ! method_exists($relationObj, 'getRelated')) {
            return;
        }

        $related = $relationObj->getRelated();

        if (! $related instanceof Model) {
            return;
        }

        $innerPredicate = $this->extractInnerPredicateFromSubquery($subQuery, $related);

        if (! $innerPredicate instanceof PredicateNode) {
            return;
        }

        $this->pendingHasRewrites[] = new PendingHasRewrite(
            relation: $relation,
            not: $not,
            innerPredicate: $innerPredicate,
            whereOffset: $beforeOffset,
        );
    }

    private function extractInnerPredicateFromSubquery(QueryBuilder $subQuery, Model $related): ?PredicateNode
    {
        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $subQuery->wheres;
        $nodes = [];

        $tablePrefix = ModelMetadata::table($related).'.';
        $deletedAt = method_exists($related, 'getDeletedAtColumn')
            ? $related->getDeletedAtColumn()
            : 'deleted_at';
        $qualifiedDeletedAt = method_exists($related, 'getQualifiedDeletedAtColumn')
            ? $related->getQualifiedDeletedAtColumn()
            : ModelMetadata::table($related).'.'.$deletedAt;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            // Column-to-column compares are the relation constraint (e.g. posts.user_id = users.id).
            // The relation graph already encodes that linkage, so skip them.
            if ($type === 'Column') {
                continue;
            }

            // Soft-delete global scope on the related model; the per-model fingerprint
            // already covers default-scope rows, and the column is often absent from
            // the stored attribute facts.
            if ($type === 'Null'
                && $boolean === 'and'
                && is_string($column)
                && in_array($column, [$deletedAt, $qualifiedDeletedAt], true)
            ) {
                continue;
            }

            if ($boolean !== 'and') {
                return null;
            }

            // Stored attribute facts use unqualified column names — normalize before
            // handing off to PredicateExtractor.
            if (is_string($column) && str_starts_with($column, $tablePrefix)) {
                $where = ['column' => substr($column, strlen($tablePrefix))] + $where;
            }

            $node = PredicateExtractor::fromWhere($where);

            if (! $node instanceof PredicateNode) {
                return null;
            }

            $nodes[] = $node;
        }

        if ($nodes === []) {
            return new AndNode([]);
        }

        if (count($nodes) === 1) {
            return $nodes[0];
        }

        return new AndNode($nodes);
    }

    /** @return list<int> */
    private function pendingHasRewriteOffsets(): array
    {
        return array_map(static fn (PendingHasRewrite $r): int => $r->whereOffset, $this->pendingHasRewrites);
    }

    /** @return QueryPatternExtractor<TModel> */
    private function makeExtractor(): QueryPatternExtractor
    {
        /** @var QueryPatternExtractor<TModel> $extractor */
        $extractor = new QueryPatternExtractor($this, $this->pendingHasRewriteOffsets());

        return $extractor;
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

        // Queries with non-string SELECT expressions (withCount, selectRaw, …) add
        // virtual columns to each row that are never stored in the identity map.
        // Returning a cached model would silently drop those computed attributes.
        if ((new QueryPatternExtractor($this))->hasNonStringSelectColumns()) {
            return $this->withoutIdentityMap()->whereKey($id)->first($columns);
        }

        $connection = ModelMetadata::connection($this->getModel());
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $entry = $store->find(
            connection: $connection,
            modelClass: $model::class,
            table: ModelMetadata::table($model),
            primaryKeyName: $model->getKeyName(),
            primaryKeyValue: $id,
            fingerprint: $fingerprint,
        );

        if ($entry !== null && $entry->state === LifecycleState::Exists) {
            $hitMode = $this->tryServeFromMemory($entry, $columns);

            if ($hitMode !== null) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnModelFromMemory,
                    modelClass: $model::class,
                    reason: $hitMode === 'backfilled' ? 'exact-primary-key-hit-after-backfill' : 'exact-primary-key-hit',
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
        }

        if ($store->isAbsent(
            connection: $connection,
            modelClass: $model::class,
            table: ModelMetadata::table($model),
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

        $store->setPendingFingerprint($fingerprint);
        try {
            $result = $this->whereKey($id)->first($columns);
        } finally {
            $store->setPendingFingerprint(null);
        }

        if ($result instanceof Model) {
            if ($this->fetchedAllColumns($columns)) {
                $store->markAllColumnsKnown($result, $fingerprint);
            }
        } elseif ($result === null) {
            $store->recordAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: ModelMetadata::table($model),
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

        $connection = ModelMetadata::connection($this->getModel());
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        // --- single primary-key lookup ---
        $primaryKeyId = $extractor->extractSinglePrimaryKeyLookup();

        if ($primaryKeyId !== null) {
            $entry = $store->find(
                connection: $connection,
                modelClass: $model::class,
                table: ModelMetadata::table($model),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            );

            if ($entry !== null && $entry->state === LifecycleState::Exists) {
                $hitMode = $this->tryServeFromMemory($entry, $columns);
            } else {
                $hitMode = null;
            }

            if ($entry !== null && $entry->state === LifecycleState::Exists && $hitMode !== null) {
                $hasResult = $this->pendingHasRewrites === []
                    ? EvaluationResult::Match
                    : $this->evaluateHasRewrites($entry->model, $store, resolve(IdentityGraph::class));

                if ($hasResult === EvaluationResult::Match) {
                    $store->capture(new Explanation(
                        type: $this->pendingHasRewrites === []
                            ? PlanType::ReturnCollectionFromMemory
                            : $this->coveragePlanType(PlanType::ReturnCollectionFromMemory),
                        modelClass: $model::class,
                        reason: $hitMode === 'backfilled' ? 'exact-primary-key-hit-via-where-after-backfill' : 'exact-primary-key-hit-via-where',
                        sqlExecuted: false,
                        memoryKeys: [$primaryKeyId],
                    ));

                    /** @var TModel $cached */
                    $cached = $entry->model;

                    return [$cached];
                }

                if ($hasResult === EvaluationResult::Reject) {
                    $store->capture(new Explanation(
                        type: $this->coveragePlanType(PlanType::ReturnEmptyCollection),
                        modelClass: $model::class,
                        reason: 'where-has-filter-rejected',
                        sqlExecuted: false,
                    ));

                    return [];
                }
                // Unknown → fall through to SQL.
            }

            if ($store->isAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: ModelMetadata::table($model),
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
                table: ModelMetadata::table($model),
                primaryKeyName: $model->getKeyName(),
                keys: $keySet,
                fingerprint: $fingerprint,
                columns: $columns,
            );

            $memoryModels = [];
            $memoryKeys = [];
            $rejectedKeys = [];
            $sqlKeys = $unknownKeys;
            $hasExtraNodes = $extraPredicateNodes !== [];
            $hasRewrites = $this->pendingHasRewrites !== [];

            if ($hasExtraNodes || $hasRewrites) {
                $evaluator = PredicateEvaluator::forModel($model);
                $predicate = $hasExtraNodes ? new AndNode($extraPredicateNodes) : null;
                $processTruth = $this->isProcessTruth();
                $graph = resolve(IdentityGraph::class);

                foreach ($hits as $hitKey => $hitEntry) {
                    if ($processTruth) {
                        $hitEntry->attributes->syncFromModel($hitEntry->model);
                    }

                    $result = $predicate instanceof AndNode
                        ? $evaluator->evaluate($hitEntry->attributes, $predicate, $processTruth)
                        : EvaluationResult::Match;

                    if ($result !== EvaluationResult::Reject && $hasRewrites) {
                        $hasResult = $this->evaluateHasRewrites($hitEntry->model, $store, $graph);
                        $result = $this->combineEvaluations($result, $hasResult);
                    }

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
                $planType = $this->keySetPlanType($hasRewrites, $hasExtraNodes, sqlPath: false);
                $reason = $this->keySetReason($hasRewrites, $hasExtraNodes, sqlPath: false);

                $store->capture(new Explanation(
                    type: $planType,
                    modelClass: $model::class,
                    reason: $reason,
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

            $store->setPendingFingerprint($fingerprint);
            try {
                /** @var array<int, TModel> $fetched */
                $fetched = $rewriteBuilder->getModels($columns);
            } finally {
                $store->setPendingFingerprint(null);
            }

            $isFullSelect = $this->fetchedAllColumns($columns);

            $fetchedByKey = [];
            foreach ($fetched as $fetchedModel) {
                if ($isFullSelect) {
                    $store->markAllColumnsKnown($fetchedModel, $fingerprint);
                }
                $k = $fetchedModel->getKey();
                if (is_int($k) || is_string($k)) {
                    $fetchedByKey[$k] = true;
                }
            }

            // With has-rewrites in play a missing fetched key can mean
            // "whereHas EXISTS filtered it out" rather than "row absent".
            if (! $hasRewrites) {
                foreach ($unknownKeys as $unknownKey) {
                    if (! isset($fetchedByKey[$unknownKey])) {
                        $store->recordAbsent(
                            connection: $connection,
                            modelClass: $model::class,
                            table: ModelMetadata::table($model),
                            primaryKeyName: $model->getKeyName(),
                            primaryKeyValue: $unknownKey,
                            fingerprint: $fingerprint,
                        );
                    }
                }
            }

            $planType = $this->keySetPlanType($hasRewrites, $hasExtraNodes, sqlPath: true);
            $reason = $this->keySetReason($hasRewrites, $hasExtraNodes, sqlPath: true);

            $store->capture(new Explanation(
                type: $planType,
                modelClass: $model::class,
                reason: $reason,
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
                table: ModelMetadata::table($model),
                fingerprint: $fingerprint,
                equalityValues: $uniqueKeyValues,
            );

            $processTruth = $this->isProcessTruth();
            $uniqueEntry = $this->revalidateUniqueEntry($uniqueEntry, $processTruth);

            $uniqueHitMode = ($uniqueEntry instanceof IdentityEntry && $uniqueEntry->state === LifecycleState::Exists)
                ? $this->tryServeFromMemory($uniqueEntry, $columns)
                : null;

            if ($uniqueEntry instanceof IdentityEntry && $uniqueEntry->state === LifecycleState::Exists && $uniqueHitMode !== null) {
                $evalResult = EvaluationResult::Match;

                if ($extraNodes !== []) {
                    $evaluator = PredicateEvaluator::forModel($model);
                    $evalResult = $evaluator->evaluate($uniqueEntry->attributes, new AndNode($extraNodes), $processTruth);
                }

                if ($evalResult !== EvaluationResult::Reject && $this->pendingHasRewrites !== []) {
                    $hasResult = $this->evaluateHasRewrites($uniqueEntry->model, $store, resolve(IdentityGraph::class));
                    $evalResult = $this->combineEvaluations($evalResult, $hasResult);
                }

                if ($evalResult === EvaluationResult::Reject) {
                    $store->capture(new Explanation(
                        type: $this->coveragePlanType(PlanType::ReturnEmptyCollection),
                        modelClass: $model::class,
                        reason: $this->pendingHasRewrites !== [] ? 'where-has-filter-rejected' : 'unique-key-hit-predicate-rejected',
                        sqlExecuted: false,
                    ));

                    return [];
                }

                if ($evalResult === EvaluationResult::Match) {
                    $store->capture(new Explanation(
                        type: $this->coveragePlanType(PlanType::ReturnCollectionFromMemory),
                        modelClass: $model::class,
                        reason: $uniqueHitMode === 'backfilled' ? 'unique-key-positive-hit-after-backfill' : 'unique-key-positive-hit',
                        sqlExecuted: false,
                        memoryKeys: [$uniqueEntry->primaryKeyValue],
                    ));

                    /** @var TModel $cached */
                    $cached = $uniqueEntry->model;

                    return [$cached];
                }
                // Unknown → fall through to SQL below
            }

            if ($extraNodes === [] && ! $processTruth && $store->isAbsentByUniqueKey(
                connection: $connection,
                modelClass: $model::class,
                table: ModelMetadata::table($model),
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

            $store->setPendingFingerprint($fingerprint);
            try {
                $models = parent::getModels($columns);
            } finally {
                $store->setPendingFingerprint(null);
            }

            $isFullSelect = $this->fetchedAllColumns($columns);

            foreach ($models as $result) {
                if ($isFullSelect) {
                    $store->markAllColumnsKnown($result, $fingerprint);
                }
            }

            if ($models === [] && $extraNodes === []) {
                $store->recordAbsentByUniqueKey(
                    connection: $connection,
                    modelClass: $model::class,
                    table: ModelMetadata::table($model),
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
                type: $this->coveragePlanType(PlanType::ReturnCollectionFromCoverage),
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return $coveredModels;
        }

        // --- fallthrough: execute SQL normally ---
        $store->setPendingFingerprint($fingerprint);
        try {
            $models = parent::getModels($columns);
        } finally {
            $store->setPendingFingerprint(null);
        }

        $isFullSelect = $this->fetchedAllColumns($columns);

        foreach ($models as $result) {
            if ($isFullSelect) {
                $store->markAllColumnsKnown($result, $fingerprint);
            }
        }

        if ($primaryKeyId !== null && $models === []) {
            $store->recordAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: ModelMetadata::table($model),
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

        // The unique-key / coverage shortcuts below don't evaluate whereHas in
        // memory; defer to SQL (which still applies the EXISTS) until exists()
        // is wired through the has-rewrite evaluator.
        if ($this->pendingHasRewrites !== []) {
            return parent::exists();
        }

        $uniqueIndexes = $store->uniqueIndexesForModelClass($this->getModel()::class);
        $extractor = $this->makeExtractor();
        $uniqueExtraction = $extractor->extractUniqueKeyLookup($uniqueIndexes);

        if ($uniqueExtraction === null) {
            // --- coverage check for exists ---
            return $this->existsFromCoverageOrSql($store, $extractor);
        }

        [$uniqueKeyValues, $extraNodes] = $uniqueExtraction;

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);

        $entry = $store->findByUniqueKey(
            connection: $connection,
            modelClass: $model::class,
            table: ModelMetadata::table($model),
            fingerprint: $fingerprint,
            equalityValues: $uniqueKeyValues,
        );

        $processTruth = $this->isProcessTruth();
        $entry = $this->revalidateUniqueEntry($entry, $processTruth);

        if ($entry instanceof IdentityEntry && $entry->state === LifecycleState::Exists) {
            if ($extraNodes !== []) {
                $evaluator = PredicateEvaluator::forModel($model);
                $evalResult = $evaluator->evaluate($entry->attributes, new AndNode($extraNodes), $processTruth);

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

        if ($extraNodes === [] && ! $processTruth && $store->isAbsentByUniqueKey(
            connection: $connection,
            modelClass: $model::class,
            table: ModelMetadata::table($model),
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

        if (! $result && $extraNodes === []) {
            $store->recordAbsentByUniqueKey(
                connection: $connection,
                modelClass: $model::class,
                table: ModelMetadata::table($model),
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
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

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

    /** @param Expression|string $column */
    public function sum($column): mixed
    {
        if ($this->identityMapDisabled) {
            return parent::sum($column);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! is_string($column)) {
            return parent::sum($column);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        $coveredModels = $this->getModelsFromCoverage([$column], $store, $connection, $fingerprint, $extractor);

        if ($coveredModels === null) {
            return parent::sum($column);
        }

        $total = 0;

        foreach ($coveredModels as $m) {
            $val = $m->getAttribute($column);

            if (! is_int($val) && ! is_float($val)) {
                return parent::sum($column);
            }

            $total += $val;
        }

        $store->capture(new Explanation(
            type: PlanType::ReturnSumFromCoverage,
            modelClass: $model::class,
            reason: 'coverage-subset-hit',
            sqlExecuted: false,
        ));

        return $total;
    }

    /** @param Expression|string $column */
    public function min($column): mixed
    {
        if ($this->identityMapDisabled) {
            return parent::min($column);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! is_string($column)) {
            return parent::min($column);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        $coveredModels = $this->getModelsFromCoverage([$column], $store, $connection, $fingerprint, $extractor);

        if ($coveredModels === null) {
            return parent::min($column);
        }

        if ($coveredModels === []) {
            $store->capture(new Explanation(
                type: PlanType::ReturnMinFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return null;
        }

        $values = [];

        foreach ($coveredModels as $m) {
            $val = $m->getAttribute($column);

            if (! is_int($val) && ! is_float($val)) {
                return parent::min($column);
            }

            $values[] = $val;
        }

        $store->capture(new Explanation(
            type: PlanType::ReturnMinFromCoverage,
            modelClass: $model::class,
            reason: 'coverage-subset-hit',
            sqlExecuted: false,
        ));

        return min($values);
    }

    /** @param Expression|string $column */
    public function max($column): mixed
    {
        if ($this->identityMapDisabled) {
            return parent::max($column);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! is_string($column)) {
            return parent::max($column);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        $coveredModels = $this->getModelsFromCoverage([$column], $store, $connection, $fingerprint, $extractor);

        if ($coveredModels === null) {
            return parent::max($column);
        }

        if ($coveredModels === []) {
            $store->capture(new Explanation(
                type: PlanType::ReturnMaxFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return null;
        }

        $values = [];

        foreach ($coveredModels as $m) {
            $val = $m->getAttribute($column);

            if (! is_int($val) && ! is_float($val)) {
                return parent::max($column);
            }

            $values[] = $val;
        }

        $store->capture(new Explanation(
            type: PlanType::ReturnMaxFromCoverage,
            modelClass: $model::class,
            reason: 'coverage-subset-hit',
            sqlExecuted: false,
        ));

        return max($values);
    }

    /** @param Expression|string $column */
    public function avg($column): mixed
    {
        if ($this->identityMapDisabled) {
            return parent::avg($column);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! is_string($column)) {
            return parent::avg($column);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        $coveredModels = $this->getModelsFromCoverage([$column], $store, $connection, $fingerprint, $extractor);

        if ($coveredModels === null) {
            return parent::avg($column);
        }

        if ($coveredModels === []) {
            $store->capture(new Explanation(
                type: PlanType::ReturnAvgFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            return null;
        }

        $values = [];

        foreach ($coveredModels as $m) {
            $val = $m->getAttribute($column);

            if (! is_int($val) && ! is_float($val)) {
                return parent::avg($column);
            }

            $values[] = $val;
        }

        $store->capture(new Explanation(
            type: PlanType::ReturnAvgFromCoverage,
            modelClass: $model::class,
            reason: 'coverage-subset-hit',
            sqlExecuted: false,
        ));

        return (float) (array_sum($values) / count($values));
    }

    /** @param Expression|string $column */
    public function average($column): mixed
    {
        return $this->avg($column);
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
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

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
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        $resolvedColumns = is_array($columns) ? $columns : [$columns];

        $coveredModels = $this->getModelsFromCoverage($resolvedColumns, $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            if ($coveredModels === []) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnFirstFromCoverage,
                    modelClass: $model::class,
                    reason: 'coverage-subset-hit',
                    sqlExecuted: false,
                ));

                return null;
            }

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

    /**
     * @param  list<string>|string  $columns
     * @return TModel
     */
    #[\Override]
    public function sole($columns = ['*']): mixed
    {
        if ($this->identityMapDisabled) {
            return parent::sole($columns);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::sole($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $extractor = $this->makeExtractor();

        $resolvedColumns = is_array($columns) ? $columns : [$columns];

        $coveredModels = $this->getModelsFromCoverage($resolvedColumns, $store, $connection, $fingerprint, $extractor);

        if ($coveredModels !== null) {
            $count = count($coveredModels);

            $store->capture(new Explanation(
                type: PlanType::ReturnSoleFromCoverage,
                modelClass: $model::class,
                reason: 'coverage-subset-hit',
                sqlExecuted: false,
            ));

            if ($count === 0) {
                throw (new ModelNotFoundException)->setModel($model::class);
            }

            if ($count > 1) {
                throw new MultipleRecordsFoundException($count);
            }

            return $coveredModels[0];
        }

        return parent::sole($columns);
    }

    #[\Override]
    public function update(array $values): mixed
    {
        $store = resolve(IdentityMapStore::class);
        $registry = resolve(CoverageRegistry::class);
        $graph = resolve(IdentityGraph::class);
        $modelClass = $this->getModel()::class;

        // Pre-compute the unqualified updated_at value (if any) so the cache mirror
        // and the SQL UPDATE share the same timestamp. Eloquent's own
        // addUpdatedAtColumn qualifies the column name (e.g. users.updated_at) which
        // is not a valid attribute key in the identity-map facts; we have to inject
        // it unqualified ourselves before parent::update is called.
        $augmentedValues = $this->addUnqualifiedUpdatedAtColumn($values);

        $predicate = $store->isDisabled() || $this->hasNonScalarValue($augmentedValues)
            ? null
            : (new QueryPatternExtractor($this))->extractFullPredicate();

        // parent::update sees updated_at already present and skips its own
        // augmentation, so the SQL UPDATE uses the same timestamp we cached.
        $result = parent::update($augmentedValues);

        if ($predicate instanceof PredicateNode) {
            $hadEvictions = $store->applyMassUpdate($modelClass, $predicate, $augmentedValues, PredicateEvaluator::forModel($this->getModel()));
            if ($hadEvictions) {
                $registry->flushModelClass($modelClass);
            } else {
                $registry->flushByColumns($modelClass, array_keys($augmentedValues));
            }
        } else {
            $store->flush($modelClass);
            $registry->flushModelClass($modelClass);
        }

        $graph->invalidateModelClass($modelClass);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function hasNonScalarValue(array $values): bool
    {
        foreach ($values as $val) {
            if ($val !== null && ! is_scalar($val)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function addUnqualifiedUpdatedAtColumn(array $values): array
    {
        $model = $this->getModel();

        if (! $model->usesTimestamps()) {
            return $values;
        }

        $column = $model->getUpdatedAtColumn();

        if (! is_string($column) || array_key_exists($column, $values)) {
            return $values;
        }

        $timestamp = $model->freshTimestampString();

        if (
            $model->hasSetMutator($column)
            || $model->hasAttributeSetMutator($column)
            || $model->hasCast($column)
        ) {
            $applied = $model->newInstance()->forceFill([$column => $timestamp])->getAttributes()[$column] ?? null;
            if (is_string($applied) || is_int($applied) || is_float($applied) || is_bool($applied)) {
                $timestamp = $applied;
            }
        }

        return [$column => $timestamp] + $values;
    }

    /**
     * Increment, decrement, and upsert bypass model events and may touch rows the
     * cache cannot reason about (the predicate can match entries we don't have
     * loaded, or upsert may insert wholly new rows under unique keys we already
     * indexed). The conservative choice is to flush the cache for the affected
     * model class after the SQL ran.
     */
    private function flushAfterBulkWrite(): void
    {
        $modelClass = $this->getModel()::class;
        resolve(IdentityMapStore::class)->flush($modelClass);
        resolve(CoverageRegistry::class)->flushModelClass($modelClass);
        resolve(IdentityGraph::class)->invalidateModelClass($modelClass);
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    #[\Override]
    public function increment($column, $amount = 1, array $extra = []): int
    {
        $result = parent::increment($column, $amount, $extra);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * @param  string|Expression  $column
     * @param  float|int  $amount
     * @param  array<string, mixed>  $extra
     */
    #[\Override]
    public function decrement($column, $amount = 1, array $extra = []): int
    {
        $result = parent::decrement($column, $amount, $extra);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * incrementEach / decrementEach are declared only on the underlying Query
     * Builder in some Laravel versions (Eloquent\Builder picks them up via
     * __call). Routing through toBase() lets us flush regardless of where the
     * method lives, and avoids the `#[\Override]` attribute fataling on
     * Laravel versions that don't expose them on the parent class. Same goes
     * for the other write paths we override on this class — Rector's
     * AddOverrideAttributeToOverriddenMethodsRector is skipped for this file
     * in rector.php precisely to keep the matrix green.
     *
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function incrementEach(array $columns, array $extra = []): int
    {
        $result = $this->toBase()->incrementEach($columns, $this->addUpdatedAtColumn($extra));
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * @param  array<string, float|int|numeric-string>  $columns
     * @param  array<string, mixed>  $extra
     */
    public function decrementEach(array $columns, array $extra = []): int
    {
        $result = $this->toBase()->decrementEach($columns, $this->addUpdatedAtColumn($extra));
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $values
     * @param  array<int, string>|string  $uniqueBy
     * @param  array<int, string>|null  $update
     */
    #[\Override]
    public function upsert(array $values, $uniqueBy, $update = null): int
    {
        $result = parent::upsert($values, $uniqueBy, $update);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * Bulk raw insert paths bypass Eloquent model events, so HasIdentityMap's
     * saved hook never fires and previously-recorded coverage / graph entries
     * stay believing they're complete. We can't see the inserted rows in
     * advance, so the safest response is to flush the cache for the model
     * class once the SQL has run.
     *
     * These methods exist only on the underlying Query Builder (Eloquent\Builder
     * forwards via __call) so we cannot use parent::; we dispatch through the
     * underlying query directly.
     *
     * insert() and insertGetId() are also called by Model::performInsert for a
     * single new row — that path fires the saved event which adds the row to
     * the store, so we must not flush. We distinguish by shape: a list of
     * arrays is a true bulk insert; a flat assoc array is Model::create.
     *
     * @param  array<int|string, mixed>  $values
     */
    public function insert(array $values): bool
    {
        $result = $this->toBase()->insert($values);

        if ($this->isBulkInsertShape($values)) {
            $this->flushAfterBulkWrite();
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $values
     */
    public function insertOrIgnore(array $values): int
    {
        $result = $this->toBase()->insertOrIgnore($values);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  string|null  $sequence
     */
    public function insertGetId(array $values, $sequence = null): int
    {
        $result = $this->toBase()->insertGetId($values, $sequence);

        if ($this->isBulkInsertShape($values)) {
            $this->flushAfterBulkWrite();
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  Builder<Model>|QueryBuilder|Closure  $query
     */
    public function insertUsing(array $columns, $query): int
    {
        $result = $this->toBase()->insertUsing($columns, $query);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * Eloquent\Builder::touch dispatches to `$this->toBase()->update(...)`,
     * which bypasses both model events and our update() override. The cache
     * misses the new updated_at on touched rows.
     *
     * @param  array<int, string>|string|null  $column
     */
    #[\Override]
    public function touch($column = null): int|false
    {
        $result = parent::touch($column);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * updateOrInsert lives only on the QueryBuilder; Eloquent\Builder forwards
     * via __call. Its internal `$this->insert(...)` / `$this->update(...)` calls
     * dispatch on the QueryBuilder itself, so our overrides on this class
     * never see them. Trap the call here and flush the cache after the SQL.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|callable  $values
     */
    public function updateOrInsert(array $attributes, array|callable $values = []): bool
    {
        $result = $this->toBase()->updateOrInsert($attributes, $values);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * updateFrom is the PostgreSQL UPDATE … FROM variant. Same situation as
     * updateOrInsert: lives on QueryBuilder, bypasses our overrides.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateFrom(array $values): int
    {
        $result = $this->toBase()->updateFrom($values);
        $this->flushAfterBulkWrite();

        return $result;
    }

    /**
     * Distinguish a true bulk insert (`Post::insert([['col' => 'v'], ...])`) from
     * a single-row insert dispatched by Model::performInsert
     * (`Post::create([...])` produces a flat assoc array). The QueryBuilder
     * itself uses the same heuristic — `is_array(reset($values))` — to detect
     * bulk shape.
     *
     * @param  array<int|string, mixed>  $values
     */
    private function isBulkInsertShape(array $values): bool
    {
        if ($values === []) {
            return false;
        }

        $first = reset($values);

        return is_array($first);
    }

    #[\Override]
    public function delete(): mixed
    {
        $store = resolve(IdentityMapStore::class);
        $registry = resolve(CoverageRegistry::class);
        $graph = resolve(IdentityGraph::class);
        $modelClass = $this->getModel()::class;
        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive($modelClass), true);

        $predicate = $store->isDisabled()
            ? null
            : (new QueryPatternExtractor($this))->extractFullPredicate();

        $result = parent::delete();

        if ($predicate instanceof PredicateNode) {
            $store->applyMassDelete($modelClass, $predicate, PredicateEvaluator::forModel($this->getModel()), $usesSoftDeletes);
        } else {
            $store->flush($modelClass);
        }

        $registry->flushModelClass($modelClass);
        $graph->invalidateModelClass($modelClass);

        return $result;
    }

    #[\Override]
    public function forceDelete(): mixed
    {
        $store = resolve(IdentityMapStore::class);
        $registry = resolve(CoverageRegistry::class);
        $graph = resolve(IdentityGraph::class);
        $modelClass = $this->getModel()::class;

        $result = parent::forceDelete();

        $store->flush($modelClass);
        $registry->flushModelClass($modelClass);
        $graph->invalidateModelClass($modelClass);

        return $result;
    }

    /**
     * Try to answer a query from the coverage registry.
     *
     * Returns the filtered model array when coverage can serve the query, or
     * null when the query must fall through to SQL.
     *
     * @param  list<string>  $columns
     * @param  QueryPatternExtractor<TModel>  $extractor
     * @return list<TModel>|null
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
            table: ModelMetadata::table($model),
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
        $evaluator = PredicateEvaluator::forModel($model);
        $processTruth = $this->isProcessTruth();
        $hasRewrites = $this->pendingHasRewrites !== [];
        $graph = $hasRewrites ? resolve(IdentityGraph::class) : null;
        $result = [];

        foreach ($entry->primaryKeys as $pk) {
            $mapEntry = $store->find($connection, $model::class, ModelMetadata::table($model), $pkName, $pk, $fingerprint);

            if (! $mapEntry instanceof IdentityEntry || $mapEntry->state !== LifecycleState::Exists) {
                return null;
            }

            if ($columns !== [] && ! $mapEntry->attributes->satisfies($columns)) {
                return null;
            }

            if ($processTruth) {
                $mapEntry->attributes->syncFromModel($mapEntry->model);
            }

            $evalResult = $evaluator->evaluate($mapEntry->attributes, $region, $processTruth);

            if ($evalResult === EvaluationResult::Unknown) {
                return null;
            }

            if ($evalResult === EvaluationResult::Reject) {
                continue;
            }

            if ($hasRewrites && $graph !== null) {
                $hasResult = $this->evaluateHasRewrites($mapEntry->model, $store, $graph);

                if ($hasResult === EvaluationResult::Unknown) {
                    return null;
                }

                if ($hasResult === EvaluationResult::Reject) {
                    continue;
                }
            }

            /** @var TModel $typed */
            $typed = $mapEntry->model;
            $result[] = $typed;
        }

        return $result;
    }

    /**
     * Record a coverage entry after a SQL query executed successfully.
     *
     * @param  array<int, TModel>  $models
     * @param  list<string>  $columns
     * @param  QueryPatternExtractor<TModel>  $extractor
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
            table: ModelMetadata::table($this->getModel()),
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
    /** @param  QueryPatternExtractor<TModel>  $extractor */
    private function existsFromCoverageOrSql(IdentityMapStore $store, QueryPatternExtractor $extractor): bool
    {
        /** @var TModel $model */
        $model = $this->getModel();
        $connection = ModelMetadata::connection($model);
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

    /**
     * Under process-truth, the unique-key index is built from original values and cannot
     * reliably reflect current (dirty) state, including drift-in. Always force SQL.
     */
    private function revalidateUniqueEntry(?IdentityEntry $entry, bool $processTruth): ?IdentityEntry
    {
        return $processTruth ? null : $entry;
    }

    private function isProcessTruth(): bool
    {
        return PredicateEvaluator::isProcessTruthMode();
    }

    /**
     * Decide whether the underlying SQL actually fetched every column.
     *
     * The caller-supplied $columns is unreliable on its own: parent::first()
     * forwards ['*'] to get() / getModels() even when select() was previously
     * called, in which case the actual SQL has a narrowed column list. We must
     * cross-check against $query->columns to avoid marking partial loads as
     * "all columns known".
     *
     * @param  list<string>|array<int, string>  $columns
     */
    private function fetchedAllColumns(array $columns): bool
    {
        if ($columns !== ['*']) {
            return false;
        }

        $queryColumns = $this->getQuery()->columns;

        if ($queryColumns === null || $queryColumns === []) {
            return true;
        }

        return $queryColumns === ['*'];
    }

    /**
     * Decide whether $entry can serve a request for $columns.
     *
     * Returns 'hit' when the entry already satisfies the request, 'backfilled' when
     * the entry satisfied the request only after a narrow SELECT for missing columns,
     * or null when neither path applies and the caller must fall through to SQL.
     *
     * The 'backfilled' path is only taken when partial_models =
     * 'backfill_missing_columns'. Coverage and ['*'] requests never backfill — they
     * always fall through if not already satisfied.
     *
     * @param  list<string>|array<int, string>  $columns
     */
    private function tryServeFromMemory(IdentityEntry $entry, array $columns): ?string
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

    private function evaluateHasRewrites(Model $candidate, IdentityMapStore $store, IdentityGraph $graph): EvaluationResult
    {
        $candidateIdentity = ModelIdentity::fromModel($candidate);

        if (! $candidateIdentity instanceof ModelIdentity) {
            return EvaluationResult::Unknown;
        }

        foreach ($this->pendingHasRewrites as $rewrite) {
            $existsResult = $this->evaluateSingleHasRewrite($candidate, $candidateIdentity, $rewrite, $store, $graph);

            if ($existsResult === EvaluationResult::Unknown) {
                return EvaluationResult::Unknown;
            }

            $expected = $rewrite->not ? EvaluationResult::Reject : EvaluationResult::Match;

            if ($existsResult !== $expected) {
                return EvaluationResult::Reject;
            }
        }

        return EvaluationResult::Match;
    }

    private function evaluateSingleHasRewrite(
        Model $candidate,
        ModelIdentity $candidateIdentity,
        PendingHasRewrite $rewrite,
        IdentityMapStore $store,
        IdentityGraph $graph,
    ): EvaluationResult {
        if (! method_exists($candidate, $rewrite->relation)) {
            return EvaluationResult::Unknown;
        }

        $relation = $candidate->{$rewrite->relation}();
        $evaluator = PredicateEvaluator::forModel($relation->getRelated());

        if ($relation instanceof BelongsTo) {
            return $this->evaluateBelongsToHas($candidate, $relation, $rewrite->innerPredicate, $store, $evaluator);
        }

        if ($relation instanceof HasMany || $relation instanceof HasOne
            || $relation instanceof MorphMany || $relation instanceof MorphOne
        ) {
            return $this->evaluateChildrenHas($candidateIdentity, $rewrite, $relation->getRelated(), $store, $graph, $evaluator);
        }

        return EvaluationResult::Unknown;
    }

    /** @param BelongsTo<Model, Model> $relation */
    private function evaluateBelongsToHas(
        Model $candidate,
        BelongsTo $relation,
        PredicateNode $innerPredicate,
        IdentityMapStore $store,
        PredicateEvaluator $evaluator,
    ): EvaluationResult {
        $fkValue = $candidate->getAttribute($relation->getForeignKeyName());

        if ($fkValue === null) {
            return EvaluationResult::Reject;
        }

        if (! is_int($fkValue) && ! is_string($fkValue)) {
            return EvaluationResult::Unknown;
        }

        $related = $relation->getRelated();

        if ($relation->getOwnerKeyName() !== $related->getKeyName()) {
            return EvaluationResult::Unknown;
        }

        $parentEntry = $store->find(
            connection: $related->getConnectionName() ?? 'default',
            modelClass: $related::class,
            table: ModelMetadata::table($related),
            primaryKeyName: $related->getKeyName(),
            primaryKeyValue: $fkValue,
            fingerprint: ScopeFingerprinter::fromModel($related),
        );

        if (! $parentEntry instanceof IdentityEntry) {
            return EvaluationResult::Unknown;
        }

        if ($parentEntry->state !== LifecycleState::Exists) {
            return EvaluationResult::Reject;
        }

        $processTruth = $this->isProcessTruth();

        if ($processTruth) {
            $parentEntry->attributes->syncFromModel($parentEntry->model);
        }

        return $evaluator->evaluate($parentEntry->attributes, $innerPredicate, $processTruth);
    }

    private function evaluateChildrenHas(
        ModelIdentity $parentIdentity,
        PendingHasRewrite $rewrite,
        Model $related,
        IdentityMapStore $store,
        IdentityGraph $graph,
        PredicateEvaluator $evaluator,
    ): EvaluationResult {
        $coverage = $graph->coverageFor($parentIdentity, $rewrite->relation);

        if (! $coverage instanceof RelationCoverage || ! $coverage->complete) {
            return EvaluationResult::Unknown;
        }

        if ($coverage->childPrimaryKeys === []) {
            return EvaluationResult::Reject;
        }

        $connection = $related->getConnectionName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromModel($related);
        $processTruth = $this->isProcessTruth();
        $hasUnknown = false;

        foreach ($coverage->childPrimaryKeys as $pk) {
            $childEntry = $store->find(
                connection: $connection,
                modelClass: $related::class,
                table: ModelMetadata::table($related),
                primaryKeyName: $related->getKeyName(),
                primaryKeyValue: $pk,
                fingerprint: $fingerprint,
            );

            if (! $childEntry instanceof IdentityEntry || $childEntry->state !== LifecycleState::Exists) {
                return EvaluationResult::Unknown;
            }

            if ($processTruth) {
                $childEntry->attributes->syncFromModel($childEntry->model);
            }

            $childResult = $evaluator->evaluate($childEntry->attributes, $rewrite->innerPredicate, $processTruth);

            if ($childResult === EvaluationResult::Match) {
                return EvaluationResult::Match;
            }

            if ($childResult === EvaluationResult::Unknown) {
                $hasUnknown = true;
            }
        }

        return $hasUnknown ? EvaluationResult::Unknown : EvaluationResult::Reject;
    }

    private function combineEvaluations(EvaluationResult $a, EvaluationResult $b): EvaluationResult
    {
        if ($a === EvaluationResult::Reject || $b === EvaluationResult::Reject) {
            return EvaluationResult::Reject;
        }

        if ($a === EvaluationResult::Unknown || $b === EvaluationResult::Unknown) {
            return EvaluationResult::Unknown;
        }

        return EvaluationResult::Match;
    }

    private function keySetPlanType(bool $hasRewrites, bool $hasExtraNodes, bool $sqlPath): PlanType
    {
        if ($hasRewrites) {
            foreach ($this->pendingHasRewrites as $r) {
                if ($r->not) {
                    return PlanType::WhereDoesntHaveFromGraph;
                }
            }

            return PlanType::WhereHasFromGraph;
        }

        if ($sqlPath) {
            return $hasExtraNodes ? PlanType::RewritePredicateAndMerge : PlanType::RewritePrimaryKeysAndMerge;
        }

        return $hasExtraNodes ? PlanType::RewritePredicateAndMerge : PlanType::ReturnCollectionFromMemory;
    }

    private function keySetReason(bool $hasRewrites, bool $hasExtraNodes, bool $sqlPath): string
    {
        if ($hasRewrites) {
            return $sqlPath ? 'has-rewrite-prune-and-rewrite' : 'has-rewrite-pruned-all-known';
        }

        if ($sqlPath) {
            return $hasExtraNodes ? 'predicate-prune-and-rewrite' : 'key-set-rewrite';
        }

        return $hasExtraNodes ? 'predicate-pruned-all-known' : 'all-keys-known-or-absent';
    }

    private function coveragePlanType(PlanType $fallback): PlanType
    {
        if ($this->pendingHasRewrites === []) {
            return $fallback;
        }

        foreach ($this->pendingHasRewrites as $r) {
            if ($r->not) {
                return PlanType::WhereDoesntHaveFromGraph;
            }
        }

        return PlanType::WhereHasFromGraph;
    }
}
