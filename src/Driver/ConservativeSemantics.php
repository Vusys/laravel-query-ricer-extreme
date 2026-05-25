<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Vusys\QueryRicerExtreme\Enums\EvaluationResult;

/**
 * Default fallback profile when no driver-specific semantics are wired.
 *
 * Uses strict type-aware equality: cross-type comparisons return Unknown
 * rather than guess. Driver-specific profiles ({@see SqliteSemantics},
 * {@see MySqlSemantics}…) handle the safe coercions their engines perform.
 */
final class ConservativeSemantics implements DriverSemantics
{
    #[\Override]
    public function compare(mixed $left, string $operator, mixed $right, ColumnSemantics $column): EvaluationResult
    {
        if ($left === null || $right === null) {
            return EvaluationResult::Unknown;
        }

        if (in_array($operator, ['=', '!=', '<>'], true)) {
            if (gettype($left) !== gettype($right)) {
                return EvaluationResult::Unknown;
            }

            $equal = $left === $right;
            $expectEqual = $operator === '=';

            return $equal === $expectEqual ? EvaluationResult::Match : EvaluationResult::Reject;
        }

        return $this->compareOrdered($left, $operator, $right);
    }

    #[\Override]
    public function compareForOrder(mixed $left, mixed $right, ColumnSemantics $column): ?int
    {
        if ((! is_int($left) && ! is_float($left)) || (! is_int($right) && ! is_float($right))) {
            return null;
        }

        return $left <=> $right;
    }

    #[\Override]
    public function nullOrdering(string $direction): NullOrdering
    {
        return strtolower($direction) === 'desc' ? NullOrdering::NullsFirst : NullOrdering::NullsLast;
    }

    /** @param '>'|'>='|'<'|'<=' $operator */
    private function compareOrdered(mixed $left, string $operator, mixed $right): EvaluationResult
    {
        if ((! is_int($left) && ! is_float($left)) || (! is_int($right) && ! is_float($right))) {
            return EvaluationResult::Unknown;
        }

        $matched = match ($operator) {
            '>' => $left > $right,
            '>=' => $left >= $right,
            '<' => $left < $right,
            '<=' => $left <= $right,
        };

        return $matched ? EvaluationResult::Match : EvaluationResult::Reject;
    }
}
