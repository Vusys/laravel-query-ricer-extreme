<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class PredicateEvaluatorTest extends TestCase
{
    private PredicateEvaluator $evaluator;

    #[\Override]
    protected function setUp(): void
    {
        $this->evaluator = new PredicateEvaluator;
    }

    /** @param array<string, mixed> $values */
    private function attributes(array $values): AttributeKnowledge
    {
        $knowledge = new AttributeKnowledge;

        foreach ($values as $column => $value) {
            $knowledge->set($column, new AttributeFact(
                column: $column,
                originalValue: $value,
                currentValue: $value,
                isDirty: false,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            ));
        }

        return $knowledge;
    }

    /**
     * Build attributes where originalValue and currentValue differ, simulating a dirty model.
     *
     * @param  array<string, array{original: mixed, current: mixed}>  $values
     */
    private function dirtyAttributes(array $values): AttributeKnowledge
    {
        $knowledge = new AttributeKnowledge;

        foreach ($values as $column => ['original' => $original, 'current' => $current]) {
            $knowledge->set($column, new AttributeFact(
                column: $column,
                originalValue: $original,
                currentValue: $current,
                isDirty: true,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            ));
        }

        return $knowledge;
    }

    // --- ComparisonNode ---

    #[Test]
    public function equality_matches_equal_string(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new ComparisonNode('status', '=', 'active');

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function equality_rejects_different_string(): void
    {
        $attrs = $this->attributes(['status' => 'disabled']);
        $node = new ComparisonNode('status', '=', 'active');

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function equality_matches_int_to_bool(): void
    {
        // raw attribute is 1 (SQLite boolean), predicate value is true
        $attrs = $this->attributes(['active' => 1]);
        $node = new ComparisonNode('active', '=', true);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function equality_rejects_int_zero_against_true(): void
    {
        $attrs = $this->attributes(['active' => 0]);
        $node = new ComparisonNode('active', '=', true);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function equality_unknown_when_column_missing(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);
        $node = new ComparisonNode('status', '=', 'active');

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_equal_matches_different_value(): void
    {
        $attrs = $this->attributes(['status' => 'disabled']);
        $node = new ComparisonNode('status', '!=', 'active');

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_equal_rejects_same_value(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new ComparisonNode('status', '!=', 'active');

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function diamond_not_equal_operator_rejects_same_value(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new ComparisonNode('status', '<>', 'active');

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function unsupported_operator_returns_unknown(): void
    {
        $attrs = $this->attributes(['age' => 25]);
        $node = new ComparisonNode('age', '>', 18);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    // --- InNode ---

    #[Test]
    public function in_matches_when_value_in_list(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new InNode('status', ['active', 'pending'], false);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function in_rejects_when_value_not_in_list(): void
    {
        $attrs = $this->attributes(['status' => 'disabled']);
        $node = new InNode('status', ['active', 'pending'], false);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_in_matches_when_value_absent_from_list(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new InNode('status', ['disabled', 'banned'], true);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_in_rejects_when_value_in_list(): void
    {
        $attrs = $this->attributes(['status' => 'disabled']);
        $node = new InNode('status', ['disabled', 'banned'], true);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function in_unknown_when_column_missing(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);
        $node = new InNode('status', ['active'], false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    // --- NullNode ---

    #[Test]
    public function null_matches_when_attribute_is_null(): void
    {
        $attrs = $this->attributes(['deleted_at' => null]);
        $node = new NullNode('deleted_at', false);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function null_rejects_when_attribute_is_not_null(): void
    {
        $attrs = $this->attributes(['deleted_at' => '2024-01-01']);
        $node = new NullNode('deleted_at', false);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_null_matches_when_attribute_has_value(): void
    {
        $attrs = $this->attributes(['email' => 'a@b.com']);
        $node = new NullNode('email', true);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function not_null_rejects_when_attribute_is_null(): void
    {
        $attrs = $this->attributes(['email' => null]);
        $node = new NullNode('email', true);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function null_unknown_when_column_missing(): void
    {
        $attrs = $this->attributes(['name' => 'Alice']);
        $node = new NullNode('deleted_at', false);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    // --- AndNode ---

    #[Test]
    public function and_empty_children_always_matches(): void
    {
        $attrs = $this->attributes([]);
        $node = new AndNode([]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_all_match_returns_match(): void
    {
        $attrs = $this->attributes(['status' => 'active', 'name' => 'Alice']);
        $node = new AndNode([
            new ComparisonNode('status', '=', 'active'),
            new ComparisonNode('name', '=', 'Alice'),
        ]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_any_reject_returns_reject(): void
    {
        $attrs = $this->attributes(['status' => 'disabled', 'name' => 'Alice']);
        $node = new AndNode([
            new ComparisonNode('name', '=', 'Alice'),
            new ComparisonNode('status', '=', 'active'),
        ]);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_reject_wins_over_unknown(): void
    {
        $attrs = $this->attributes(['status' => 'disabled']);
        $node = new AndNode([
            new ComparisonNode('status', '=', 'active'),   // Reject
            new ComparisonNode('missing_col', '=', 'x'),   // Unknown
        ]);

        $this->assertSame(EvaluationResult::Reject, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_unknown_when_all_match_or_unknown_but_some_unknown(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new AndNode([
            new ComparisonNode('status', '=', 'active'),      // Match
            new ComparisonNode('missing_col', '=', 'value'),  // Unknown
        ]);

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    #[Test]
    public function and_nested_nodes_work_correctly(): void
    {
        $attrs = $this->attributes(['status' => 'active', 'role' => 'admin']);
        $node = new AndNode([
            new ComparisonNode('status', '=', 'active'),
            new InNode('role', ['admin', 'superuser'], false),
        ]);

        $this->assertSame(EvaluationResult::Match, $this->evaluator->evaluate($attrs, $node));
    }

    // --- Unknown node type ---

    #[Test]
    public function unknown_node_type_returns_unknown(): void
    {
        $attrs = $this->attributes(['status' => 'active']);
        $node = new class implements PredicateNode {};

        $this->assertSame(EvaluationResult::Unknown, $this->evaluator->evaluate($attrs, $node));
    }

    // --- process-truth routing ---

    #[Test]
    public function default_uses_original_value_not_current_value(): void
    {
        $attrs = $this->dirtyAttributes(['status' => ['original' => 'active', 'current' => 'disabled']]);
        $node = new ComparisonNode('status', '=', 'active');

        $this->assertSame(
            EvaluationResult::Match,
            $this->evaluator->evaluate($attrs, $node),
            'Default (no $processTruth) must read originalValue',
        );
    }

    #[Test]
    public function process_truth_false_uses_original_value(): void
    {
        $attrs = $this->dirtyAttributes(['status' => ['original' => 'active', 'current' => 'disabled']]);
        $node = new ComparisonNode('status', '=', 'active');

        $this->assertSame(
            EvaluationResult::Match,
            $this->evaluator->evaluate($attrs, $node, processTruth: false),
        );
    }

    #[Test]
    public function process_truth_true_uses_current_value(): void
    {
        $attrs = $this->dirtyAttributes(['status' => ['original' => 'active', 'current' => 'disabled']]);
        $node = new ComparisonNode('status', '=', 'active');

        $this->assertSame(
            EvaluationResult::Reject,
            $this->evaluator->evaluate($attrs, $node, processTruth: true),
            '$processTruth=true must read currentValue',
        );
    }

    #[Test]
    public function null_node_default_uses_original_value(): void
    {
        // originalValue is null (IS NULL would match), currentValue is non-null
        $attrs = $this->dirtyAttributes(['deleted_at' => ['original' => null, 'current' => '2024-01-01']]);
        $node = new NullNode('deleted_at', false);

        $this->assertSame(
            EvaluationResult::Match,
            $this->evaluator->evaluate($attrs, $node),
            'Default routing must read originalValue (null) for NullNode',
        );
    }

    #[Test]
    public function null_node_process_truth_uses_current_value(): void
    {
        // originalValue is null, currentValue is non-null
        $attrs = $this->dirtyAttributes(['deleted_at' => ['original' => null, 'current' => '2024-01-01']]);
        $node = new NullNode('deleted_at', false);

        $this->assertSame(
            EvaluationResult::Reject,
            $this->evaluator->evaluate($attrs, $node, processTruth: true),
            '$processTruth=true must read currentValue (non-null) for NullNode',
        );
    }

    #[Test]
    public function null_node_process_truth_is_null_when_current_is_null(): void
    {
        // originalValue is non-null, currentValue is null
        $attrs = $this->dirtyAttributes(['deleted_at' => ['original' => '2024-01-01', 'current' => null]]);
        $node = new NullNode('deleted_at', false);

        $this->assertSame(
            EvaluationResult::Match,
            $this->evaluator->evaluate($attrs, $node, processTruth: true),
            'IS NULL matches when currentValue is null under process-truth',
        );
    }
}
