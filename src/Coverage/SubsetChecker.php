<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Coverage;

use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class SubsetChecker
{
    /**
     * Returns true if every row satisfying $query also satisfies $recorded.
     *
     * Conservatively returns false when the relationship cannot be proven from
     * the phase-one predicate node types (=, !=, IN, NOT IN, IS NULL, IS NOT NULL).
     * OR nodes, range comparisons, and unsupported operators all yield false.
     */
    public function isSubset(PredicateNode $query, PredicateNode $recorded): bool
    {
        // R2 ⊆ AND(D1, D2, ...) iff R2 ⊆ D1 AND R2 ⊆ D2 AND ...
        // Empty AND = tautology (matches everything) — any query is a subset.
        if ($recorded instanceof AndNode) {
            foreach ($recorded->children as $child) {
                if (! $this->isSubset($query, $child)) {
                    return false;
                }
            }

            return true;
        }

        // AND(C1, C2, ...) ⊆ R1 if any Ci ⊆ R1 (AND adds constraints, only narrows).
        if ($query instanceof AndNode) {
            foreach ($query->children as $child) {
                if ($this->isSubset($child, $recorded)) {
                    return true;
                }
            }

            return false;
        }

        if ($query instanceof ComparisonNode && $recorded instanceof ComparisonNode) {
            return $this->comparisonSubset($query, $recorded);
        }

        // col = v ⊆ col IN (v, ...) when recorded is non-negated
        if ($query instanceof ComparisonNode && $recorded instanceof InNode) {
            return ! $recorded->negated
                && $query->operator === '='
                && $query->column === $recorded->column
                && $this->inLoose($query->value, $recorded->values);
        }

        // col IN (s) ⊆ col IN (t) when both non-negated and s ⊆ t
        if ($query instanceof InNode && $recorded instanceof InNode) {
            if ($query->negated || $recorded->negated || $query->column !== $recorded->column) {
                return false;
            }

            foreach ($query->values as $v) {
                if (! $this->inLoose($v, $recorded->values)) {
                    return false;
                }
            }

            return true;
        }

        // col IN (v) ⊆ col = v  (single-element non-negated IN)
        if ($query instanceof InNode && $recorded instanceof ComparisonNode) {
            return ! $query->negated
                && $recorded->operator === '='
                && $query->column === $recorded->column
                && count($query->values) === 1
                && $this->looseEquals($query->values[0], $recorded->value);
        }

        if ($query instanceof NullNode && $recorded instanceof NullNode) {
            return $query->column === $recorded->column && $query->negated === $recorded->negated;
        }

        return false;
    }

    private function comparisonSubset(ComparisonNode $query, ComparisonNode $recorded): bool
    {
        if ($query->column !== $recorded->column) {
            return false;
        }

        $qOp = $query->operator === '<>' ? '!=' : $query->operator;
        $rOp = $recorded->operator === '<>' ? '!=' : $recorded->operator;

        if ($qOp !== $rOp) {
            return false;
        }

        return $this->looseEquals($query->value, $recorded->value);
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
        return $a == $b;
    }

    /** @param list<mixed> $haystack */
    private function inLoose(mixed $needle, array $haystack): bool
    {
        foreach ($haystack as $v) {
            if ($this->looseEquals($needle, $v)) {
                return true;
            }
        }

        return false;
    }
}
