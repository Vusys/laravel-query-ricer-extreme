<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class PredicateEvaluator
{
    public function evaluate(AttributeKnowledge $attributes, PredicateNode $node): EvaluationResult
    {
        return match (true) {
            $node instanceof AndNode => $this->evaluateAnd($attributes, $node),
            $node instanceof ComparisonNode => $this->evaluateComparison($attributes, $node),
            $node instanceof InNode => $this->evaluateIn($attributes, $node),
            $node instanceof NullNode => $this->evaluateNull($attributes, $node),
            default => EvaluationResult::Unknown,
        };
    }

    private function evaluateAnd(AttributeKnowledge $attributes, AndNode $node): EvaluationResult
    {
        if ($node->children === []) {
            return EvaluationResult::Match;
        }

        $hasUnknown = false;

        foreach ($node->children as $child) {
            $result = $this->evaluate($attributes, $child);

            if ($result === EvaluationResult::Reject) {
                return EvaluationResult::Reject;
            }

            if ($result === EvaluationResult::Unknown) {
                $hasUnknown = true;
            }
        }

        return $hasUnknown ? EvaluationResult::Unknown : EvaluationResult::Match;
    }

    private function evaluateComparison(AttributeKnowledge $attributes, ComparisonNode $node): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $attrValue = $fact->originalValue;
        $predicateValue = $node->value;

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

    private function evaluateIn(AttributeKnowledge $attributes, InNode $node): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $attrValue = $fact->originalValue;
        $found = false;

        foreach ($node->values as $value) {
            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            if ($attrValue == $value) {
                $found = true;
                break;
            }
        }

        if ($node->negated) {
            return $found ? EvaluationResult::Reject : EvaluationResult::Match;
        }

        return $found ? EvaluationResult::Match : EvaluationResult::Reject;
    }

    private function evaluateNull(AttributeKnowledge $attributes, NullNode $node): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $isNull = $fact->originalValue === null;

        // negated = IS NOT NULL
        if ($node->negated) {
            return $isNull ? EvaluationResult::Reject : EvaluationResult::Match;
        }

        // IS NULL
        return $isNull ? EvaluationResult::Match : EvaluationResult::Reject;
    }
}
