<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Knowledge\RelationFact;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
final class MemoryHasMany extends HasMany
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

        if (! $this->parent->relationLoaded($this->relationName)) {
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

        $loadedCollection = $this->parent->getRelation($this->relationName);

        if (! $loadedCollection instanceof Collection) {
            /** @var Collection<int, TRelatedModel> $r */
            $r = $this->query->get($columns);

            return $r;
        }

        if ($extraNodes === []) {
            $store->capture(new Explanation(
                type: PlanType::FilterHasManyInMemory,
                modelClass: $this->related::class,
                reason: 'has-many-no-extra-predicates',
                sqlExecuted: false,
            ));

            /** @var Collection<int, TRelatedModel> $typed */
            $typed = $loadedCollection;

            return $typed;
        }

        $predicate = new AndNode($extraNodes);
        $evaluator = new PredicateEvaluator;
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

            $result = $evaluator->evaluate($entry->attributes, $predicate);

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
            reason: 'has-many-filtered-in-memory',
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
                kind: RelationKind::HasMany,
                loaded: true,
                complete: true,
                value: null,
            ));
        }

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
                    kind: RelationKind::HasMany,
                    loaded: true,
                    complete: true,
                    value: null,
                ));
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
}
