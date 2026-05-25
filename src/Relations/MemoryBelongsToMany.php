<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Relations;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Enums\RelationKind;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Graph\EdgeConfidence;
use Vusys\QueryRicerExtreme\Graph\EdgeSource;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Graph\PivotCoverage;
use Vusys\QueryRicerExtreme\Graph\PivotEdge;
use Vusys\QueryRicerExtreme\Knowledge\RelationFact;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\BetweenNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
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
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
final class MemoryBelongsToMany extends BelongsToMany
{
    /**
     * @param  array<int, string>  $columns
     * @return Collection<int, TRelatedModel>
     */
    #[\Override]
    public function get($columns = ['*']): Collection
    {
        if ($columns !== ['*']) {
            return parent::get($columns);
        }

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::get($columns);
        }

        if ($this->queryHasHazards()) {
            return parent::get($columns);
        }

        $extraction = $this->extractExtraPredicates();

        if ($extraction === null) {
            return parent::get($columns);
        }

        $relatedNodes = $extraction[0];
        $pivotNodes = $extraction[1];
        $pivotColumnsRequested = $extraction[2];

        $fromGraph = $this->getFromGraph($store, $relatedNodes, $pivotNodes, $pivotColumnsRequested);

        if ($fromGraph instanceof Collection) {
            /** @var Collection<int, TRelatedModel> $fromGraph */
            return $fromGraph;
        }

        $r = parent::get($columns);

        if ($relatedNodes === [] && $pivotNodes === []) {
            $this->recordGraphCoverageForParent($this->parent, $r);
        }

        return $r;
    }

    /**
     * @param  array<int, TDeclaringModel>  $models
     * @param  Collection<int, TRelatedModel>  $results
     * @return array<int, TDeclaringModel>
     */
    #[\Override]
    public function match(array $models, Collection $results, $relation): array
    {
        /** @var array<int, TDeclaringModel> $matched */
        $matched = parent::match($models, $results, $relation);

        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled() || ! $this->isCleanLoad()) {
            return $matched;
        }

        foreach ($models as $model) {
            $entry = $store->findEntry($model);

            if ($entry !== null) {
                $entry->relations->set($relation, new RelationFact(
                    name: $relation,
                    kind: RelationKind::BelongsToMany,
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

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     */
    #[\Override]
    public function attach($id, array $attributes = [], $touch = true): void
    {
        parent::attach($id, $attributes, $touch);

        if (! $this->isGraphEnabled()) {
            return;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);
        $relationName = $this->getRelationName();

        if (! $parentIdentity instanceof ModelIdentity || $relationName === '') {
            return;
        }

        // attach() does not prove coverage and may produce duplicate pivot rows;
        // any existing edges / coverage are now stale. The next read re-observes
        // the full set from SQL.
        $graph = resolve(IdentityGraph::class);
        $graph->forgetPivotCoverage($parentIdentity, $relationName);
        $graph->clearPivotEdgesFor($parentIdentity, $relationName);
    }

    /**
     * @param  mixed  $ids
     * @return int
     */
    #[\Override]
    public function detach($ids = null, $touch = true)
    {
        $detached = parent::detach($ids, $touch);

        if (! $this->isGraphEnabled()) {
            return $detached;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);
        $relationName = $this->getRelationName();

        if (! $parentIdentity instanceof ModelIdentity || $relationName === '') {
            return $detached;
        }

        $graph = resolve(IdentityGraph::class);

        if ($ids === null) {
            // detach() (no args) — clears all edges; coverage is now provably empty.
            $graph->clearPivotEdgesFor($parentIdentity, $relationName);
            $graph->addPivotCoverage(new PivotCoverage(
                parent: $parentIdentity,
                relationName: $relationName,
                relatedModelClass: $this->related::class,
                pivotTable: $this->table,
                complete: true,
                knownPivotColumns: $this->knownPivotColumns(),
            ));

            return $detached;
        }

        // Delegate to the same parser parent::detach() used so we accept Model,
        // EloquentCollection, BaseCollection, array, or scalar identically.
        foreach ($this->parseIds($ids) as $relatedKey) {
            if (! is_int($relatedKey) && ! is_string($relatedKey)) {
                continue;
            }

            $relatedIdentity = $this->relatedIdentityFromKey($relatedKey);
            $graph->removePivotEdge($parentIdentity, $relationName, $relatedIdentity);
        }

        // Coverage status is preserved: detach with specific ids on covered region leaves it covered.

        return $detached;
    }

    /**
     * @param  Collection<int, Model>|\Illuminate\Support\Collection<int, mixed>|array<int|string, mixed>|Model|int|string  $ids
     * @return array<string, array<int, int|string>>
     */
    #[\Override]
    public function sync($ids, $detaching = true)
    {
        $changes = parent::sync($ids, $detaching);

        if (! $this->isGraphEnabled()) {
            return $changes;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);
        $relationName = $this->getRelationName();

        if (! $parentIdentity instanceof ModelIdentity || $relationName === '') {
            return $changes;
        }

        $graph = resolve(IdentityGraph::class);
        $intended = $this->normalizeSyncIds($ids);

        if ($intended === null) {
            // Could not fully understand the intended set (e.g. unsupported shape).
            // Conservative: drop coverage and let the next read re-prove.
            $graph->forgetPivotCoverage($parentIdentity, $relationName);
            $graph->clearPivotEdgesFor($parentIdentity, $relationName);

            return $changes;
        }

        if (! $detaching) {
            // syncWithoutDetaching is attach-many — cannot prove coverage and may
            // overlap existing pivot rows. Drop everything; next read repopulates.
            $graph->forgetPivotCoverage($parentIdentity, $relationName);
            $graph->clearPivotEdgesFor($parentIdentity, $relationName);

            return $changes;
        }

        // sync($ids, detaching=true) — intended set is the complete set.
        $graph->clearPivotEdgesFor($parentIdentity, $relationName);

        foreach ($intended as [$relatedKey, $pivotAttrs]) {
            $relatedIdentity = $this->relatedIdentityFromKey($relatedKey);

            $graph->addPivotEdge(new PivotEdge(
                parent: $parentIdentity,
                relationName: $relationName,
                related: $relatedIdentity,
                pivotTable: $this->table,
                pivotAttributes: $pivotAttrs,
                source: EdgeSource::Pivot,
                confidence: EdgeConfidence::Certain,
                version: 1,
            ));
        }

        $graph->addPivotCoverage(new PivotCoverage(
            parent: $parentIdentity,
            relationName: $relationName,
            relatedModelClass: $this->related::class,
            pivotTable: $this->table,
            complete: true,
            knownPivotColumns: $this->knownPivotColumns(),
        ));

        return $changes;
    }

    /**
     * @param  mixed  $ids
     * @return array<string, array<int, int|string>>
     */
    #[\Override]
    public function toggle($ids, $touch = true)
    {
        $changes = parent::toggle($ids, $touch);

        if (! $this->isGraphEnabled()) {
            return $changes;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);
        $relationName = $this->getRelationName();

        if (! $parentIdentity instanceof ModelIdentity || $relationName === '') {
            return $changes;
        }

        // toggle's outcome depends on the prior set, which we may not know in memory.
        // Conservative: invalidate coverage and edges.
        $graph = resolve(IdentityGraph::class);
        $graph->forgetPivotCoverage($parentIdentity, $relationName);
        $graph->clearPivotEdgesFor($parentIdentity, $relationName);

        return $changes;
    }

    /**
     * @param  mixed  $id
     * @param  array<string, mixed>  $attributes
     * @return int
     */
    #[\Override]
    public function updateExistingPivot($id, array $attributes, $touch = true)
    {
        $updated = parent::updateExistingPivot($id, $attributes, $touch);

        if (! $this->isGraphEnabled()) {
            return $updated;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);
        $relationName = $this->getRelationName();

        if (! $parentIdentity instanceof ModelIdentity || $relationName === '') {
            return $updated;
        }

        // The DB row now has new pivot attribute values, but cached PivotEdge
        // objects still hold the old ones. We could surgically patch the
        // matching edge, but a relation-scoped flush is simpler and safe: the
        // next read repopulates from SQL with the fresh values.
        $graph = resolve(IdentityGraph::class);
        $graph->forgetPivotCoverage($parentIdentity, $relationName);
        $graph->clearPivotEdgesFor($parentIdentity, $relationName);

        // Mirror the flush on the inverse side: the same pivot row backs a
        // BelongsToMany on the related model, and its cached pivot edges /
        // coverage would otherwise return stale attribute values too.
        // `$this->related` is the relation's prototype instance (no PK), so
        // we hydrate a thin identity for the specific related row by id.
        $inverseRelationName = $this->resolveInverseRelationName();
        $relatedKey = $this->normalizeUpdateExistingPivotId($id);
        $relatedIdentity = ($inverseRelationName !== null && $relatedKey !== null)
            ? $this->relatedIdentityFromKey($relatedKey)
            : null;

        if ($relatedIdentity instanceof ModelIdentity) {
            /** @var string $inverseRelationName $relatedIdentity is only non-null when we resolved the inverse name */
            $graph->forgetPivotCoverage($relatedIdentity, $inverseRelationName);
            $graph->clearPivotEdgesFor($relatedIdentity, $inverseRelationName);
        }

        return $updated;
    }

    /**
     * Normalise the $id passed to updateExistingPivot — accepts a Model
     * instance or a scalar — to the int|string primary key the graph keys on.
     *
     * @param  mixed  $id
     */
    private function normalizeUpdateExistingPivotId($id): int|string|null
    {
        if ($id instanceof Model) {
            $key = $id->getKey();

            return is_int($key) || is_string($key) ? $key : null;
        }

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Find the name of the BelongsToMany relation on $this->related that points
     * back to $this->parent's class via the same pivot table. Returns null if
     * the related model does not declare an inverse relation. Results are
     * cached per (related-class, parent-class, pivot-table) tuple to keep the
     * reflection cost flat across repeated calls.
     */
    private function resolveInverseRelationName(): ?string
    {
        static $cache = [];

        $relatedClass = $this->related::class;
        $parentClass = $this->parent::class;
        $pivotTable = $this->table;
        $cacheKey = $relatedClass.'|'.$parentClass.'|'.$pivotTable;

        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        $reflection = new \ReflectionClass($relatedClass);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $relatedClass) {
                // Skip inherited framework methods; the inverse relation is
                // expected to live on the related model directly.
                continue;
            }

            $returnType = $method->getReturnType();

            if (! $returnType instanceof \ReflectionNamedType) {
                continue;
            }

            $typeName = $returnType->getName();

            if ($typeName !== BelongsToMany::class
                && ! is_subclass_of($typeName, BelongsToMany::class)
            ) {
                continue;
            }

            try {
                $relation = $this->related->{$method->getName()}();
            } catch (\Throwable) {
                continue;
            }

            if (! $relation instanceof BelongsToMany) {
                continue;
            }

            if ($relation->getRelated()::class === $parentClass && $relation->getTable() === $pivotTable) {
                return $cache[$cacheKey] = $method->getName();
            }
        }

        return $cache[$cacheKey] = null;
    }

    /**
     * @param  list<PredicateNode>  $relatedNodes
     * @param  list<PredicateNode>  $pivotNodes
     * @param  list<string>  $pivotColumnsRequested
     * @return Collection<int, TRelatedModel>|null
     */
    private function getFromGraph(
        IdentityMapStore $store,
        array $relatedNodes,
        array $pivotNodes,
        array $pivotColumnsRequested,
    ): ?Collection {
        if (! $this->isGraphEnabled()) {
            return null;
        }

        $relationName = $this->getRelationName();

        if ($relationName === '') {
            return null;
        }

        $parentIdentity = ModelIdentity::fromModel($this->parent);

        if (! $parentIdentity instanceof ModelIdentity) {
            return null;
        }

        $graph = resolve(IdentityGraph::class);
        $coverage = $graph->pivotCoverageFor($parentIdentity, $relationName);

        if ($coverage === null || ! $coverage->complete) {
            return null;
        }

        foreach ($pivotColumnsRequested as $col) {
            if (! in_array($col, $coverage->knownPivotColumns, true)) {
                return null;
            }
        }

        $pivotEdges = $graph->pivotEdgesFrom($parentIdentity, $relationName);
        $evaluator = PredicateEvaluator::forModel($this->related);
        $processTruth = PredicateEvaluator::isProcessTruthMode();
        $relatedPredicate = $relatedNodes === [] ? null : new AndNode($relatedNodes);
        $pivotPredicate = $pivotNodes === [] ? null : new AndNode($pivotNodes);
        $filtered = [];

        foreach ($pivotEdges as $pivotEdge) {
            if ($pivotPredicate instanceof AndNode) {
                $pivotResult = $this->evaluatePivotPredicate($pivotEdge, $pivotPredicate);

                if ($pivotResult === EvaluationResult::Unknown) {
                    return null;
                }

                if ($pivotResult === EvaluationResult::Reject) {
                    continue;
                }
            }

            $relatedEntry = $this->fetchRelatedEntry($store, $pivotEdge->related);

            if (! $relatedEntry instanceof IdentityEntry) {
                return null;
            }

            if ($relatedPredicate instanceof AndNode) {
                if ($processTruth) {
                    $relatedEntry->attributes->syncFromModel($relatedEntry->model);
                }

                $relResult = $evaluator->evaluate($relatedEntry->attributes, $relatedPredicate, $processTruth);

                if ($relResult === EvaluationResult::Unknown) {
                    return null;
                }

                if ($relResult === EvaluationResult::Reject) {
                    continue;
                }
            }

            // Clone before attaching the pivot so we never mutate the shared
            // canonical instance held by the identity map — otherwise pivot
            // attributes from one parent would leak into reads for another.
            /** @var TRelatedModel $typed */
            $typed = clone $relatedEntry->model;
            $this->attachPivotToModel($typed, $pivotEdge->pivotAttributes);
            $filtered[] = $typed;
        }

        $reason = ($pivotNodes === [] && $relatedNodes === [])
            ? 'belongs-to-many-from-graph-coverage'
            : 'belongs-to-many-graph-coverage-filtered';

        $planType = $pivotNodes !== []
            ? PlanType::WherePivotInMemory
            : PlanType::BelongsToManyFromGraph;

        $store->capture(new Explanation(
            type: $planType,
            modelClass: $this->related::class,
            reason: $reason,
            sqlExecuted: false,
        ));

        return $this->related->newCollection($filtered);
    }

    private function evaluatePivotPredicate(
        PivotEdge $edge,
        AndNode $predicate,
    ): EvaluationResult {
        $hasUnknown = false;

        foreach ($predicate->children as $child) {
            $result = $this->evaluateSinglePivotNode($edge, $child);

            if ($result === EvaluationResult::Reject) {
                return EvaluationResult::Reject;
            }

            if ($result === EvaluationResult::Unknown) {
                $hasUnknown = true;
            }
        }

        return $hasUnknown ? EvaluationResult::Unknown : EvaluationResult::Match;
    }

    private function evaluateSinglePivotNode(PivotEdge $edge, PredicateNode $node): EvaluationResult
    {
        $column = $this->pivotColumnOf($node);

        if ($column === null) {
            return EvaluationResult::Unknown;
        }

        if (! array_key_exists($column, $edge->pivotAttributes)) {
            return EvaluationResult::Unknown;
        }

        $attrValue = $edge->pivotAttributes[$column];

        if ($node instanceof ComparisonNode) {
            $predicateValue = $node->value;

            if ($attrValue === null || $predicateValue === null) {
                return EvaluationResult::Unknown;
            }

            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            return match ($node->operator) {
                '=' => $attrValue == $predicateValue
                    ? EvaluationResult::Match
                    : EvaluationResult::Reject,
                '!=', '<>' => $attrValue != $predicateValue
                    ? EvaluationResult::Match
                    : EvaluationResult::Reject,
                '>', '>=', '<', '<=' => $this->evaluateNumericComparison($attrValue, $node->operator, $predicateValue),
                default => EvaluationResult::Unknown,
            };
        }

        if ($node instanceof NullNode) {
            $isNull = $attrValue === null;

            return $node->negated
                ? ($isNull ? EvaluationResult::Reject : EvaluationResult::Match)
                : ($isNull ? EvaluationResult::Match : EvaluationResult::Reject);
        }

        if ($node instanceof InNode) {
            if ($attrValue === null) {
                return EvaluationResult::Unknown;
            }

            $found = false;
            $hasNullInList = false;

            foreach ($node->values as $value) {
                if ($value === null) {
                    $hasNullInList = true;

                    continue;
                }

                // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
                if ($attrValue == $value) {
                    $found = true;
                    break;
                }
            }

            if (! $found && $hasNullInList) {
                return EvaluationResult::Unknown;
            }

            if ($node->negated) {
                return $found ? EvaluationResult::Reject : EvaluationResult::Match;
            }

            return $found ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        if ($node instanceof BetweenNode) {
            if (! is_int($attrValue) && ! is_float($attrValue)
                || ! is_int($node->min) && ! is_float($node->min)
                || ! is_int($node->max) && ! is_float($node->max)
            ) {
                return EvaluationResult::Unknown;
            }

            $inRange = $attrValue >= $node->min && $attrValue <= $node->max;

            return $node->negated
                ? ($inRange ? EvaluationResult::Reject : EvaluationResult::Match)
                : ($inRange ? EvaluationResult::Match : EvaluationResult::Reject);
        }

        return EvaluationResult::Unknown;
    }

    /** @param '>'|'>='|'<'|'<=' $op */
    private function evaluateNumericComparison(mixed $a, string $op, mixed $b): EvaluationResult
    {
        if (! is_int($a) && ! is_float($a) || ! is_int($b) && ! is_float($b)) {
            return EvaluationResult::Unknown;
        }

        return match ($op) {
            '>' => $a > $b ? EvaluationResult::Match : EvaluationResult::Reject,
            '>=' => $a >= $b ? EvaluationResult::Match : EvaluationResult::Reject,
            '<' => $a < $b ? EvaluationResult::Match : EvaluationResult::Reject,
            '<=' => $a <= $b ? EvaluationResult::Match : EvaluationResult::Reject,
        };
    }

    private function pivotColumnOf(PredicateNode $node): ?string
    {
        $raw = match (true) {
            $node instanceof ComparisonNode => $node->column,
            $node instanceof NullNode => $node->column,
            $node instanceof InNode => $node->column,
            $node instanceof BetweenNode => $node->column,
            default => null,
        };

        if (! is_string($raw)) {
            return null;
        }

        $prefix = $this->table.'.';

        if (str_starts_with($raw, $prefix)) {
            return substr($raw, strlen($prefix));
        }

        return $raw;
    }

    private function fetchRelatedEntry(IdentityMapStore $store, ModelIdentity $relatedIdentity): ?IdentityEntry
    {
        $related = $this->related;
        $connection = $related->getConnectionName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromModel($related);

        $entry = $store->find(
            connection: $connection,
            modelClass: $related::class,
            table: $related->getTable(),
            primaryKeyName: $related->getKeyName(),
            primaryKeyValue: $relatedIdentity->primaryKeyValue,
            fingerprint: $fingerprint,
        );

        if (! $entry instanceof IdentityEntry || $entry->state !== LifecycleState::Exists) {
            return null;
        }

        return $entry;
    }

    /** @param array<string, mixed> $pivotAttrs */
    private function attachPivotToModel(Model $model, array $pivotAttrs): void
    {
        $pivot = $this->newExistingPivot($pivotAttrs);
        $model->setRelation($this->accessor, $pivot);
    }

    /**
     * @return array{0: list<PredicateNode>, 1: list<PredicateNode>, 2: list<string>}|null
     *                                                                                     Returns [relatedNodes, pivotNodes, requestedPivotColumns] or null when a where could not be parsed.
     */
    private function extractExtraPredicates(): ?array
    {
        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $this->query->getQuery()->wheres;
        $relatedNodes = [];
        $pivotNodes = [];
        $pivotColumnsRequested = [];

        foreach ($wheres as $where) {
            if ($this->isBaseFkConstraint($where)) {
                continue;
            }

            if ($this->isSafeGlobalScopeWhere($where)) {
                continue;
            }

            $boolean = $where['boolean'] ?? null;

            if ($boolean !== 'and') {
                return null;
            }

            $column = $where['column'] ?? null;

            if (! is_string($column)) {
                return null;
            }

            $isPivotWhere = $this->isPivotQualifiedColumn($column);
            $node = $this->buildExtractedNode($where, $isPivotWhere);

            if (! $node instanceof PredicateNode) {
                return null;
            }

            if ($isPivotWhere) {
                $pivotColumn = substr($column, strlen($this->table.'.'));
                $pivotColumnsRequested[] = $pivotColumn;
                $pivotNodes[] = $node;
            } else {
                $relatedNodes[] = $node;
            }
        }

        return [$relatedNodes, $pivotNodes, array_values(array_unique($pivotColumnsRequested))];
    }

    /** @param array<string, mixed> $where */
    private function buildExtractedNode(array $where, bool $isPivotWhere): ?PredicateNode
    {
        if (! $isPivotWhere) {
            return PredicateExtractor::fromWhere($where);
        }

        // Pivot wheres are qualified to the pivot table — strip the prefix for
        // attribute-based evaluation against PivotEdge.pivotAttributes.
        $column = $where['column'] ?? null;

        if (! is_string($column)) {
            return null;
        }

        $unqualified = substr($column, strlen($this->table.'.'));
        $stripped = $where;
        $stripped['column'] = $unqualified;

        return PredicateExtractor::fromWhere($stripped);
    }

    private function isCleanLoad(): bool
    {
        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $this->query->getQuery()->wheres;

        foreach ($wheres as $where) {
            if ($this->isBaseFkConstraint($where)) {
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
    private function isBaseFkConstraint(array $where): bool
    {
        $type = $where['type'] ?? null;
        $boolean = $where['boolean'] ?? null;
        $column = $where['column'] ?? null;
        $operator = $where['operator'] ?? null;

        if (! is_string($column) || $boolean !== 'and') {
            return false;
        }

        $foreignKey = $this->getQualifiedForeignPivotKeyName();

        if ($column !== $foreignKey) {
            return false;
        }

        if ($type === 'Basic' && $operator === '=') {
            return true;
        }

        if ($type === 'In' || $type === 'InRaw') {
            return true;
        }

        return $type === 'NotNull';
    }

    private function isPivotQualifiedColumn(string $column): bool
    {
        $prefix = $this->table.'.';

        return str_starts_with($column, $prefix);
    }

    private function queryHasHazards(): bool
    {
        $query = $this->query->getQuery();
        $joins = $query->joins ?? [];

        // BelongsToMany always adds exactly one inner join against the pivot table.
        // Anything beyond that is a hazard we can't reason about.
        if (count($joins) !== 1) {
            return true;
        }

        return ($query->unions !== null && $query->unions !== [])
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

    /**
     * @param  Collection<int, TRelatedModel>  $children
     */
    private function recordGraphCoverageForParent(Model $parent, Collection $children): void
    {
        if (! $this->isGraphEnabled()) {
            return;
        }

        $relationName = $this->getRelationName();

        if ($relationName === '') {
            return;
        }

        $parentIdentity = ModelIdentity::fromModel($parent);

        if (! $parentIdentity instanceof ModelIdentity) {
            return;
        }

        $graph = resolve(IdentityGraph::class);
        $knownPivotColumns = $this->knownPivotColumns();
        // Replace the entire edge set — we just observed it in its entirety.
        $graph->clearPivotEdgesFor($parentIdentity, $relationName);

        foreach ($children as $child) {
            $relatedIdentity = ModelIdentity::fromModel($child);

            if (! $relatedIdentity instanceof ModelIdentity) {
                return;
            }

            $pivotAttrs = $this->pivotAttributesFromModel($child, $knownPivotColumns);

            $graph->addPivotEdge(new PivotEdge(
                parent: $parentIdentity,
                relationName: $relationName,
                related: $relatedIdentity,
                pivotTable: $this->table,
                pivotAttributes: $pivotAttrs,
                source: EdgeSource::Pivot,
                confidence: EdgeConfidence::Certain,
                version: 1,
            ));
        }

        $graph->addPivotCoverage(new PivotCoverage(
            parent: $parentIdentity,
            relationName: $relationName,
            relatedModelClass: $this->related::class,
            pivotTable: $this->table,
            complete: true,
            knownPivotColumns: $knownPivotColumns,
        ));
    }

    /**
     * @param  list<string>  $knownPivotColumns
     * @return array<string, mixed>
     */
    private function pivotAttributesFromModel(Model $child, array $knownPivotColumns): array
    {
        $pivot = $child->getRelation($this->accessor);

        if (! $pivot instanceof Model) {
            return [];
        }

        $attrs = [];

        foreach ($knownPivotColumns as $column) {
            if (array_key_exists($column, $pivot->getAttributes())) {
                $attrs[$column] = $pivot->getAttribute($column);
            }
        }

        return $attrs;
    }

    /** @return list<string> */
    private function knownPivotColumns(): array
    {
        $columns = [$this->foreignPivotKey, $this->relatedPivotKey];

        foreach ($this->pivotColumns as $col) {
            if (is_string($col)) {
                $columns[] = $col;
            }
        }

        return array_values(array_unique($columns));
    }

    /**
     * @param  mixed  $ids
     * @return list<array{0: int|string, 1: array<string, mixed>}>|null
     */
    private function normalizeSyncIds($ids): ?array
    {
        if ($ids instanceof Collection || $ids instanceof \Illuminate\Support\Collection) {
            $ids = $ids->all();
        }

        if (! is_array($ids)) {
            return null;
        }

        $out = [];

        foreach ($ids as $k => $v) {
            if (is_array($v)) {
                /** @var array<string, mixed> $vTyped */
                $vTyped = $v;
                $out[] = [$k, $vTyped];
            } elseif (is_int($v) || is_string($v)) {
                $out[] = [$v, []];
            } elseif ($v instanceof Model) {
                $key = $v->getKey();

                if (! is_int($key) && ! is_string($key)) {
                    return null;
                }

                $out[] = [$key, []];
            } else {
                return null;
            }
        }

        return $out;
    }

    private function relatedIdentityFromKey(int|string $relatedKey): ModelIdentity
    {
        $related = $this->related;

        return new ModelIdentity(
            connection: $related->getConnectionName() ?? 'default',
            modelClass: $related::class,
            table: $related->getTable(),
            primaryKeyName: $related->getKeyName(),
            primaryKeyValue: $relatedKey,
            scopeFingerprint: ScopeFingerprinter::fromModel($related),
        );
    }

    private function isGraphEnabled(): bool
    {
        return (bool) config('query-ricer-extreme.relation_graph.enabled', true);
    }
}
