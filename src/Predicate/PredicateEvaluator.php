<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;

final class PredicateEvaluator
{
    public function evaluate(AttributeKnowledge $attributes, PredicateNode $node, bool $processTruth = false): EvaluationResult
    {
        return match (true) {
            $node instanceof AndNode => $this->evaluateAnd($attributes, $node, $processTruth),
            $node instanceof ComparisonNode => $this->evaluateComparison($attributes, $node, $processTruth),
            $node instanceof InNode => $this->evaluateIn($attributes, $node, $processTruth),
            $node instanceof NullNode => $this->evaluateNull($attributes, $node, $processTruth),
            default => EvaluationResult::Unknown,
        };
    }

    private function evaluateAnd(AttributeKnowledge $attributes, AndNode $node, bool $processTruth): EvaluationResult
    {
        if ($node->children === []) {
            return EvaluationResult::Match;
        }

        $hasUnknown = false;

        foreach ($node->children as $child) {
            $result = $this->evaluate($attributes, $child, $processTruth);

            if ($result === EvaluationResult::Reject) {
                return EvaluationResult::Reject;
            }

            if ($result === EvaluationResult::Unknown) {
                $hasUnknown = true;
            }
        }

        return $hasUnknown ? EvaluationResult::Unknown : EvaluationResult::Match;
    }

    private function evaluateComparison(AttributeKnowledge $attributes, ComparisonNode $node, bool $processTruth): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $attrValue = $processTruth ? $fact->currentValue : $fact->originalValue;
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
            default => EvaluationResult::Unknown,
        };
    }

    private function evaluateIn(AttributeKnowledge $attributes, InNode $node, bool $processTruth): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $attrValue = $processTruth ? $fact->currentValue : $fact->originalValue;

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

    private function evaluateNull(AttributeKnowledge $attributes, NullNode $node, bool $processTruth): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $attrValue = $processTruth ? $fact->currentValue : $fact->originalValue;
        $isNull = $attrValue === null;

        // negated = IS NOT NULL
        if ($node->negated) {
            return $isNull ? EvaluationResult::Reject : EvaluationResult::Match;
        }

        // IS NULL
        return $isNull ? EvaluationResult::Match : EvaluationResult::Reject;
    }
}
