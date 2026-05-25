<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Predicate;

use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\ConservativeSemantics;
use Vusys\QueryRicerExtreme\Driver\DriverSemantics;
use Vusys\QueryRicerExtreme\Driver\DriverSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\NullColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;

final readonly class PredicateEvaluator
{
    private DriverSemantics $semantics;

    private ColumnSemanticsResolver $columns;

    public function __construct(
        ?DriverSemantics $semantics = null,
        ?ColumnSemanticsResolver $columns = null,
        /**
         * Model instance used to resolve per-column semantics on the correct
         * connection. When null, every column resolves to ColumnSemantics::unknown().
         */
        private ?Model $model = null,
    ) {
        $this->semantics = $semantics ?? new ConservativeSemantics;
        $this->columns = $columns ?? new NullColumnSemanticsResolver;
    }

    /**
     * Build an evaluator wired to the given model's connection-aware driver
     * semantics and schema-discovered column metadata. Service container is
     * consulted; falls back to a plain evaluator when the package services
     * are not registered (e.g. in pure-unit-test contexts).
     */
    public static function forModel(Model $model): self
    {
        $app = function_exists('app') ? app() : null;

        if ($app === null
            || ! $app->bound(DriverSemanticsResolver::class)
            || ! $app->bound(ColumnSemanticsResolver::class)
        ) {
            return new self;
        }

        /** @var DriverSemanticsResolver $resolver */
        $resolver = $app->make(DriverSemanticsResolver::class);
        /** @var ColumnSemanticsResolver $columns */
        $columns = $app->make(ColumnSemanticsResolver::class);

        return new self(
            semantics: $resolver->forConnection($model->getConnection()),
            columns: $columns,
            model: $model,
        );
    }

    public function evaluate(AttributeKnowledge $attributes, PredicateNode $node, bool $processTruth = false): EvaluationResult
    {
        return match (true) {
            $node instanceof AndNode => $this->evaluateAnd($attributes, $node, $processTruth),
            $node instanceof ComparisonNode => $this->evaluateComparison($attributes, $node, $processTruth),
            $node instanceof InNode => $this->evaluateIn($attributes, $node, $processTruth),
            $node instanceof NullNode => $this->evaluateNull($attributes, $node, $processTruth),
            $node instanceof BetweenNode => $this->evaluateBetween($attributes, $node, $processTruth),
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

        return match ($node->operator) {
            '=', '!=', '<>', '>', '>=', '<', '<=' => $this->semantics->compare(
                $attrValue,
                $node->operator,
                $predicateValue,
                $this->columnSemantics($node->column),
            ),
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

        $column = $this->columnSemantics($node->column);
        $found = false;
        $hasUnknown = false;
        $hasNullInList = false;

        foreach ($node->values as $value) {
            if ($value === null) {
                $hasNullInList = true;

                continue;
            }

            $result = $this->semantics->compare($attrValue, '=', $value, $column);

            if ($result === EvaluationResult::Match) {
                $found = true;
                break;
            }

            if ($result === EvaluationResult::Unknown) {
                $hasUnknown = true;
            }
        }

        if (! $found && ($hasNullInList || $hasUnknown)) {
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

    private function evaluateBetween(AttributeKnowledge $attributes, BetweenNode $node, bool $processTruth): EvaluationResult
    {
        $fact = $attributes->get($node->column);

        if (! $fact instanceof AttributeFact) {
            return EvaluationResult::Unknown;
        }

        $v = $processTruth ? $fact->currentValue : $fact->originalValue;

        if ($v === null) {
            return EvaluationResult::Unknown;
        }

        $column = $this->columnSemantics($node->column);
        $lower = $this->semantics->compare($v, '>=', $node->min, $column);
        $upper = $this->semantics->compare($v, '<=', $node->max, $column);

        $inRange = match (true) {
            $lower === EvaluationResult::Match && $upper === EvaluationResult::Match => true,
            $lower === EvaluationResult::Reject || $upper === EvaluationResult::Reject => false,
            default => null,
        };

        if ($inRange === null) {
            return EvaluationResult::Unknown;
        }

        return match ($node->negated) {
            true => $inRange ? EvaluationResult::Reject : EvaluationResult::Match,
            false => $inRange ? EvaluationResult::Match : EvaluationResult::Reject,
        };
    }

    private function columnSemantics(string $column): ColumnSemantics
    {
        if (! $this->model instanceof Model) {
            return ColumnSemantics::unknown();
        }

        return $this->columns->for($this->model, $column);
    }
}
