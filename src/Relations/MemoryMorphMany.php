<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Graph\EdgeConfidence;
use Vusys\QueryRicerExtreme\Graph\EdgeSource;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Graph\RelationCoverage;
use Vusys\QueryRicerExtreme\Graph\RelationEdge;
use Vusys\QueryRicerExtreme\Knowledge\RelationFact;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Store\IdentityEntry;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class MemoryMorphMany extends MorphMany
{
    private ?string $relationName = null;

    public function withRelationName(?string $name): static
    {
        $this->relationName = $name;

        return $this;
    }

    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, TRelatedModel>
     */
    #[\Override]
    public function get($columns = ['*']): Collection
    {
        if ($this->relationName === null || $columns !== ['*']) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        if ($this->queryHasHazards()) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        $extraNodes = $this->extractExtraPredicates();

        if ($extraNodes === null) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        $loadedCollection = $this->parent->relationLoaded($this->relationName)
            ? $this->parent->getRelation($this->relationName)
            : null;

        if (! $loadedCollection instanceof Collection) {
            $fromGraph = $this->getFromGraph($store, $extraNodes);

            if ($fromGraph instanceof Collection) {
                /** @var Collection<int, TRelatedModel> $fromGraph */
                return $fromGraph;
            }

            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        $parentEntry = $store->findEntry($this->parent);
        $fact = $parentEntry?->relations->get($this->relationName);

        if ($fact === null || ! $fact->complete) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        if ($extraNodes === []) {
            $store->capture(new Explanation(
                type: PlanType::FilterHasManyInMemory,
                modelClass: $this->related::class,
                reason: 'morph-many-no-extra-predicates',
                sqlExecuted: false,
            ));

            /** @var Collection<int, TRelatedModel> $typed */
            $typed = $loadedCollection;

            return $typed;
        }

        $predicate = new AndNode($extraNodes);
        $evaluator = PredicateEvaluator::forModel($this->related);
        $processTruth = PredicateEvaluator::isProcessTruthMode();
        /** @var list<TRelatedModel> $filteredModels */
        $filteredModels = [];
        $hasUnknown = false;

        foreach ($loadedCollection as $model) {
            $key = $model->getKey();

            if (! is_int($key) && ! is_string($key)) {
                $hasUnknown = true;
                break;
            }

            $entry = $store->find(
                connection: $model->getConnectionName() ?? 'default',
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $key,
                fingerprint: ScopeFingerprinter::fromModel($model),
            );

            if ($entry === null) {
                $hasUnknown = true;
                break;
            }

            if ($processTruth) {
                $entry->attributes->syncFromModel($entry->model);
            }

            $result = $evaluator->evaluate($entry->attributes, $predicate, $processTruth);

            if ($result === EvaluationResult::Match) {
                /** @var TRelatedModel $typed */
                $typed = $model;
                $filteredModels[] = $typed;
            } elseif ($result === EvaluationResult::Unknown) {
                $hasUnknown = true;
                break;
            }
        }

        if ($hasUnknown) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        $store->capture(new Explanation(
            type: PlanType::FilterHasManyInMemory,
            modelClass: $this->related::class,
            reason: 'morph-many-filtered-in-memory',
            sqlExecuted: false,
        ));

        return $this->related->newCollection($filteredModels);
    }

    /** @return Collection<int, TRelatedModel> */
    #[\Override]
    public function getResults(): Collection
    {
        /** @var Collection<int, TRelatedModel> $result */
        $result = parent::getResults();

        if ($this->relationName === null) {
            return $result;
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! $this->isCleanLoad()) {
            return $result;
        }

        $parentEntry = $store->findEntry($this->parent);

        if ($parentEntry !== null) {
            $parentEntry->relations->set($this->relationName, new RelationFact(
                name: $this->relationName,
                kind: RelationKind::MorphMany,
                loaded: true,
                complete: true,
                value: null,
            ));
        }

        $this->recordGraphCoverageForParent($this->parent, $result);

        return $result;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  Collection<int, TRelatedModel>  $results
     * @return array<int, TDeclaringModel>
     */
    #[\Override]
    public function matchMany(array $models, Collection $results, $relation): array
    {
        /** @var array<int, TDeclaringModel> $matched */
        $matched = parent::matchMany($models, $results, $relation);

        if ($this->relationName === null) {
            return $matched;
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! $this->isCleanLoad()) {
            return $matched;
        }

        foreach ($models as $model) {
            $entry = $store->findEntry($model);

            if ($entry !== null) {
                $entry->relations->set($relation, new RelationFact(
                    name: $relation,
                    kind: RelationKind::MorphMany,
                    loaded: true,
                    complete: true,
                    value: null,
                ));
            }

            $loaded = $model->getRelation($relation);

            if ($loaded instanceof Collection) {
                /** @var Collection<int, TRelatedModel> $typed */
                $typed = $loaded;
                $this->recordGraphCoverageForParent($model, $typed);
            }
        }

        return $matched;
    }

    /** @return list<PredicateNode>|null null means a predicate could not be parsed */
    private function extractExtraPredicates(): ?array
    {
        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $this->query->getQuery()->wheres;
        $fkColumnQualified = $this->foreignKey;
        $fkParts = explode('.', $fkColumnQualified);
        $fkColumnUnqualified = array_pop($fkParts);
        $morphTypeQualified = $this->morphType;
        $morphTypeUnqualified = $this->getMorphType();
        $extraNodes = [];

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (is_string($column) && in_array($column, [$fkColumnQualified, $fkColumnUnqualified], true)) {
                if ($type === 'Basic' && ($where['operator'] ?? null) === '=' && $boolean === 'and') {
                    continue;
                }
                if (($type === 'In' || $type === 'InRaw') && $boolean === 'and') {
                    continue;
                }
                if ($type === 'NotNull' && $boolean === 'and') {
                    continue;
                }
            }

            if (
                $type === 'Basic'
                && is_string($column)
                && in_array($column, [$morphTypeQualified, $morphTypeUnqualified], true)
                && ($where['operator'] ?? null) === '='
                && $boolean === 'and'
            ) {
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

            $extraNodes[] = $node;
        }

        return $extraNodes;
    }

    private function isCleanLoad(): bool
    {
        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $this->query->getQuery()->wheres;
        $fkColumnQualified = $this->foreignKey;
        $fkParts = explode('.', $fkColumnQualified);
        $fkColumnUnqualified = array_pop($fkParts);
        $morphTypeQualified = $this->morphType;
        $morphTypeUnqualified = $this->getMorphType();

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (is_string($column) && in_array($column, [$fkColumnQualified, $fkColumnUnqualified], true)) {
                if ($type === 'Basic' && ($where['operator'] ?? null) === '=' && $boolean === 'and') {
                    continue;
                }
                if (($type === 'In' || $type === 'InRaw') && $boolean === 'and') {
                    continue;
                }
                if ($type === 'NotNull' && $boolean === 'and') {
                    continue;
                }
            }

            if (
                $type === 'Basic'
                && is_string($column)
                && in_array($column, [$morphTypeQualified, $morphTypeUnqualified], true)
                && ($where['operator'] ?? null) === '='
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

    private function queryHasHazards(): bool
    {
        $query = $this->query->getQuery();

        return ($query->joins !== null && $query->joins !== [])
            || ($query->unions !== null && $query->unions !== [])
            || ($query->groups !== null && $query->groups !== [])
            || ($query->havings !== null && $query->havings !== [])
            || $query->lock !== null
            || ($query->offset !== null && $query->offset > 0)
            || $query->limit !== null;
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

    /** @param Collection<int, TRelatedModel> $children */
    private function recordGraphCoverageForParent(Model $parent, Collection $children): void
    {
        if (! $this->isGraphEnabled()) {
            return;
        }

        $parentIdentity = ModelIdentity::fromModel($parent);

        if (! $parentIdentity instanceof ModelIdentity || $this->relationName === null) {
            return;
        }

        $graph = resolve(IdentityGraph::class);
        $childPrimaryKeys = [];

        foreach ($children as $child) {
            $childIdentity = ModelIdentity::fromModel($child);

            if (! $childIdentity instanceof ModelIdentity) {
                return;
            }

            $childPrimaryKeys[] = $childIdentity->primaryKeyValue;

            $graph->addEdge(new RelationEdge(
                from: $parentIdentity,
                relationName: $this->relationName,
                kind: RelationKind::MorphMany,
                to: $childIdentity,
                source: EdgeSource::LoadedRelation,
                confidence: EdgeConfidence::Certain,
                version: 1,
            ));
        }

        $graph->addCoverage(new RelationCoverage(
            parent: $parentIdentity,
            relationName: $this->relationName,
            relatedModelClass: $this->related::class,
            complete: true,
            columns: new ColumnSet(['*']),
            childPrimaryKeys: $childPrimaryKeys,
        ));
    }

    /**
     * @param  list<PredicateNode>  $extraNodes
     * @return Collection<int, TRelatedModel>|null
     */
    private function getFromGraph(IdentityMapStore $store, array $extraNodes): ?Collection
    {
        if (! $this->isGraphEnabled() || $this->relationName === null) {
            return null;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);

        if (! $parentIdentity instanceof ModelIdentity) {
            return null;
        }

        $graph = resolve(IdentityGraph::class);
        $coverage = $graph->coverageFor($parentIdentity, $this->relationName);

        if ($coverage === null || ! $coverage->complete) {
            return null;
        }

        $children = $this->fetchChildrenFromStore($store, $coverage);

        if ($children === null) {
            return null;
        }

        if ($extraNodes === []) {
            $store->capture(new Explanation(
                type: PlanType::FilterHasManyInMemory,
                modelClass: $this->related::class,
                reason: 'morph-many-from-graph-coverage',
                sqlExecuted: false,
            ));

            return $this->related->newCollection($children);
        }

        $predicate = new AndNode($extraNodes);
        $evaluator = PredicateEvaluator::forModel($this->related);
        $processTruth = PredicateEvaluator::isProcessTruthMode();
        $filtered = [];

        foreach ($children as $child) {
            $entry = $store->findEntry($child);

            if (! $entry instanceof IdentityEntry) {
                return null;
            }

            if ($processTruth) {
                $entry->attributes->syncFromModel($entry->model);
            }

            $result = $evaluator->evaluate($entry->attributes, $predicate, $processTruth);

            if ($result === EvaluationResult::Unknown) {
                return null;
            }

            if ($result === EvaluationResult::Match) {
                $filtered[] = $child;
            }
        }

        $store->capture(new Explanation(
            type: PlanType::FilterHasManyInMemory,
            modelClass: $this->related::class,
            reason: 'morph-many-graph-coverage-filtered',
            sqlExecuted: false,
        ));

        return $this->related->newCollection($filtered);
    }

    /** @return list<TRelatedModel>|null */
    private function fetchChildrenFromStore(IdentityMapStore $store, RelationCoverage $coverage): ?array
    {
        $related = $this->related;
        $connection = $related->getConnectionName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromModel($related);
        $children = [];

        foreach ($coverage->childPrimaryKeys as $pk) {
            $entry = $store->find(
                connection: $connection,
                modelClass: $related::class,
                table: $related->getTable(),
                primaryKeyName: $related->getKeyName(),
                primaryKeyValue: $pk,
                fingerprint: $fingerprint,
            );

            if (! $entry instanceof IdentityEntry || $entry->state !== LifecycleState::Exists) {
                return null;
            }

            /** @var TRelatedModel $typed */
            $typed = $entry->model;
            $children[] = $typed;
        }

        return $children;
    }

    private function isGraphEnabled(): bool
    {
        return (bool) config('query-ricer-extreme.relation_graph.enabled', true);
    }
}
