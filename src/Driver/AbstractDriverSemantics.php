<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

/**
 * Shared driver-aware behaviour for the four supported database engines.
 *
 * Integer / float / boolean / UUID comparisons are resolved confidently here:
 * every supported driver coerces tinyint↔bool, narrows float↔int the same way,
 * and uses byte-equality for UUIDs. Strings are delegated to {@see compareStrings()}
 * because that is where driver-specific collation rules diverge.
 */
abstract class AbstractDriverSemantics implements DriverSemantics
{
    #[\Override]
    public function compare(mixed $left, string $operator, mixed $right, ColumnSemantics $column): EvaluationResult
    {
        if (in_array($operator, ['=', '!=', '<>'], true)) {
            $eq = $this->equals($left, $right, $column);

            if ($eq === null) {
                return EvaluationResult::Unknown;
            }

            $expectedEqual = $operator === '=';

            return $eq === $expectedEqual ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        $cmp = $this->orderedCompare($left, $right, $column);

        if ($cmp === null) {
            return EvaluationResult::Unknown;
        }

        if ($operator === '>') {
            return $cmp > 0 ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        if ($operator === '>=') {
            return $cmp >= 0 ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        if ($operator === '<') {
            return $cmp < 0 ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        // $operator is narrowed to '<=' here by the DriverSemantics interface
        // contract — only the four ordered operators reach this branch and the
        // three preceding checks have eliminated the others.
        return $cmp <= 0 ? EvaluationResult::Match : EvaluationResult::Reject;
    }

    #[\Override]
    public function compareForOrder(mixed $left, mixed $right, ColumnSemantics $column): ?int
    {
        return $this->orderedCompare($left, $right, $column);
    }

    /**
     * Driver-specific string equality. Implementations return true/false when
     * the database would report equal / not-equal, or null when the answer
     * depends on metadata the resolver could not provide.
     */
    abstract protected function compareStrings(string $left, string $right, ColumnSemantics $column): ?bool;

    /**
     * Driver-specific string ordering. Implementations return a spaceship
     * result when the database would compare under the column's semantics
     * confidently, or null when the answer depends on metadata not available
     * to the resolver.
     */
    abstract protected function orderStrings(string $left, string $right, ColumnSemantics $column): ?int;

    private function equals(mixed $left, mixed $right, ColumnSemantics $column): ?bool
    {
        if (is_bool($left) || is_bool($right)) {
            return $this->coerceBoolean($left) === $this->coerceBoolean($right);
        }

        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }

        if (is_string($left) && is_string($right)) {
            return $this->compareStrings($left, $right, $column);
        }

        return null;
    }

    private function orderedCompare(mixed $left, mixed $right, ColumnSemantics $column): ?int
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return $left <=> $right;
        }

        if (is_string($left) && is_string($right)) {
            return $this->orderStrings($left, $right, $column);
        }

        return null;
    }

    private function coerceBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 0.0) {
            return false;
        }

        if ($value === 1 || $value === 1.0) {
            return true;
        }

        return null;
    }

    #[\Override]
    public function nullOrdering(string $direction): NullOrdering
    {
        return strtolower($direction) === 'desc' ? NullOrdering::NullsFirst : NullOrdering::NullsLast;
    }
}
