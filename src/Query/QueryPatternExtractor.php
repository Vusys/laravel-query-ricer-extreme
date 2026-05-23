<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateExtractor;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

/**
 * @template TModel of Model
 */
final readonly class QueryPatternExtractor
{
    /** @param Builder<TModel> $builder */
    public function __construct(private Builder $builder) {}

    private function hasStructuralHazards(): bool
    {
        $query = $this->builder->getQuery();

        return ($query->joins !== null && $query->joins !== [])
            || ($query->unions !== null && $query->unions !== [])
            || $query->lock !== null
            || ($query->groups !== null && $query->groups !== [])
            || ($query->havings !== null && $query->havings !== [])
            || $this->hasNonStringSelectColumns();
    }

    /**
     * Returns true when the query's SELECT list contains non-string expressions —
     * aggregate subqueries from withCount/withSum/withAvg/withExists, raw expressions
     * from selectRaw/selectSub, etc.  Such columns are never stored in the identity
     * map, so any memory-serving path that bypasses SQL would return models missing
     * those virtual attributes.
     */
    public function hasNonStringSelectColumns(): bool
    {
        $columns = $this->builder->getQuery()->columns;

        if ($columns === null || $columns === []) {
            return false;
        }

        foreach ($columns as $col) {
            if (! is_string($col)) {
                return true;
            }
        }

        return false;
    }

    public function extractSinglePrimaryKeyLookup(): int|string|null
    {
        $query = $this->builder->getQuery();

        if ($this->hasStructuralHazards()) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        $model = $this->builder->getModel();
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

    /**
     * @return array{list<int|string>, list<PredicateNode>}|null
     *                                                           Returns [keySet, extraPredicateNodes] where extraPredicateNodes is empty for pure key-set queries.
     */
    public function extractBoundedKeySet(): ?array
    {
        $query = $this->builder->getQuery();

        if ($this->hasStructuralHazards()) {
            return null;
        }

        if ($query->limit !== null || ($query->offset !== null && $query->offset > 0)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        $model = $this->builder->getModel();
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
     * Detect whether the current query is a unique-key lookup that can be served from the
     * identity map.
     *
     * Returns [uniqueKeyValues, extraPredicateNodes] when a configured unique index is
     * matched, or null when the query cannot be safely answered from the unique-key index.
     *
     * @param  list<list<string>>  $uniqueIndexes
     * @return array{array<string, mixed>, list<PredicateNode>}|null
     */
    public function extractUniqueKeyLookup(array $uniqueIndexes): ?array
    {
        if ($uniqueIndexes === []) {
            return null;
        }

        $query = $this->builder->getQuery();

        if ($this->hasStructuralHazards()) {
            return null;
        }

        if (($query->offset !== null && $query->offset > 0) || ($query->limit !== null && $query->limit < 1)) {
            return null;
        }

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        /** @var array<string, mixed> $equalityMap */
        $equalityMap = [];

        /** @var list<PredicateNode> $extraNodes */
        $extraNodes = [];

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if ($this->isSafeGlobalScopeWhere($where)) {
                continue;
            }

            if ($boolean !== 'and') {
                return null;
            }

            if ($type === 'Basic' && is_string($column) && ($where['operator'] ?? null) === '=') {
                if (array_key_exists($column, $equalityMap)) {
                    return null;
                }

                $equalityMap[$column] = $where['value'] ?? null;

                continue;
            }

            $node = PredicateExtractor::fromWhere($where);

            if (! $node instanceof PredicateNode) {
                return null;
            }

            $extraNodes[] = $node;
        }

        if ($equalityMap === []) {
            return null;
        }

        foreach ($uniqueIndexes as $indexColumns) {
            $allPresent = true;

            foreach ($indexColumns as $col) {
                if (! array_key_exists($col, $equalityMap)) {
                    $allPresent = false;
                    break;
                }
            }

            if (! $allPresent) {
                continue;
            }

            $uniqueKeyValues = [];
            $remainingEquality = $equalityMap;

            foreach ($indexColumns as $col) {
                $uniqueKeyValues[$col] = $equalityMap[$col];
                unset($remainingEquality[$col]);
            }

            $allExtraNodes = $extraNodes;

            foreach ($remainingEquality as $col => $val) {
                $allExtraNodes[] = new ComparisonNode($col, '=', $val);
            }

            return [$uniqueKeyValues, $allExtraNodes];
        }

        return null;
    }

    /**
     * Merge memory-served and SQL-fetched models in the original key-set input order.
     *
     * @param  array<int, Model>  $memoryModels
     * @param  array<int, Model>  $fetchedModels
     * @param  list<int|string>  $keyOrder
     * @return list<Model>
     */
    public static function mergeByInputOrder(array $memoryModels, array $fetchedModels, array $keyOrder): array
    {
        /** @var array<int|string, Model> $byKey */
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

    /**
     * Extract all user-provided WHERE clauses into a single predicate tree.
     *
     * Returns null if any clause uses an unsupported operator, an OR boolean,
     * or any other form that cannot be expressed as a phase-one predicate node.
     * Safe global-scope wheres (deleted_at IS NULL) are skipped since they are
     * already captured by the scope fingerprint.
     */
    public function extractFullPredicate(): ?PredicateNode
    {
        $query = $this->builder->getQuery();

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        $nodes = [];

        foreach ($wheres as $where) {
            if ($this->isSafeGlobalScopeWhere($where)) {
                continue;
            }

            if (($where['boolean'] ?? null) !== 'and') {
                return null;
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

    /**
     * Returns true when the query has no structural hazards that prevent
     * recording or serving coverage (no joins, locks, LIMIT, OFFSET, groups,
     * havings, or unions).
     */
    public function isSafeForCoverage(): bool
    {
        $query = $this->builder->getQuery();

        if ($query->joins !== null && $query->joins !== []) {
            return false;
        }

        if ($query->lock !== null) {
            return false;
        }

        if ($query->distinct) {
            return false;
        }

        if ($query->limit !== null) {
            return false;
        }

        if ($query->offset !== null && $query->offset > 0) {
            return false;
        }

        if ($query->groups !== null && $query->groups !== []) {
            return false;
        }

        if ($query->havings !== null && $query->havings !== []) {
            return false;
        }

        if ($query->unions !== null && $query->unions !== []) {
            return false;
        }

        return ! $this->hasNonStringSelectColumns();
    }

    /** @param array<string, mixed> $where */
    private function isSafeGlobalScopeWhere(array $where): bool
    {
        $model = $this->builder->getModel();
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;
        $boolean = $where['boolean'] ?? null;

        $deletedAt = method_exists($model, 'getDeletedAtColumn')
            ? $model->getDeletedAtColumn()
            : 'deleted_at';
        $qualifiedDeletedAt = method_exists($model, 'getQualifiedDeletedAtColumn')
            ? $model->getQualifiedDeletedAtColumn()
            : $model->getTable().'.'.$deletedAt;

        return $type === 'Null'
            && $boolean === 'and'
            && is_string($column)
            && in_array($column, [$deletedAt, $qualifiedDeletedAt], true);
    }
}
