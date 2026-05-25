<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Predicate;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\DriverSemantics;
use Vusys\QueryRicerExtreme\Driver\NullOrdering;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;

final class PredicateEvaluatorForModelTest extends TestCase
{
    private ?Container $previousContainer = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    #[Test]
    public function for_model_falls_back_when_container_has_no_resolver(): void
    {
        Container::setInstance(new Container);

        $model = new class extends Model {};
        $evaluator = PredicateEvaluator::forModel($model);

        // Plain evaluator with ConservativeSemantics still resolves a simple
        // numeric comparison, proving the fallback path produced a working
        // instance.
        $attrs = new AttributeKnowledge;
        $attrs->set('id', new AttributeFact(
            column: 'id',
            originalValue: 1,
            currentValue: 1,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        ));

        self::assertSame(
            EvaluationResult::Match,
            $evaluator->evaluate($attrs, new InNode('id', [1, 2], false)),
        );
    }

    #[Test]
    public function in_propagates_unknown_when_a_haystack_comparison_is_unknown(): void
    {
        // Custom semantics: comparison always returns Unknown.
        $alwaysUnknown = new class implements DriverSemantics
        {
            #[\Override]
            public function compare(mixed $left, string $operator, mixed $right, ColumnSemantics $column): EvaluationResult
            {
                return EvaluationResult::Unknown;
            }

            #[\Override]
            public function compareForOrder(mixed $left, mixed $right, ColumnSemantics $column): ?int
            {
                return null;
            }

            #[\Override]
            public function nullOrdering(string $direction): NullOrdering
            {
                return NullOrdering::NullsLast;
            }
        };

        $evaluator = new PredicateEvaluator($alwaysUnknown);

        $attrs = new AttributeKnowledge;
        $attrs->set('status', new AttributeFact(
            column: 'status',
            originalValue: 'pending',
            currentValue: 'pending',
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        ));

        self::assertSame(
            EvaluationResult::Unknown,
            $evaluator->evaluate($attrs, new InNode('status', ['a', 'b'], false)),
        );
    }
}
