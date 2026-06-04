<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Predicate;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\ConservativeSemantics;
use Vusys\QueryRicerExtreme\Driver\DriverSemantics;
use Vusys\QueryRicerExtreme\Driver\DriverSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\NullColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\NullOrdering;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
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

    #[Test]
    public function for_model_falls_back_when_only_driver_resolver_is_bound(): void
    {
        $container = new Container;
        $container->bind(DriverSemanticsResolver::class, fn (): DriverSemanticsResolver => new DriverSemanticsResolver);
        Container::setInstance($container);

        $model = new class extends Model {};
        $evaluator = PredicateEvaluator::forModel($model);

        $attrs = $this->idAttribute(1);

        // The fallback path returned a plain PredicateEvaluator (no $model), so
        // ConservativeSemantics resolves the comparison rather than attempting
        // to call $resolver->forConnection() on an unbooted anonymous model.
        self::assertSame(
            EvaluationResult::Match,
            $evaluator->evaluate($attrs, new InNode('id', [1, 2], false)),
        );
    }

    #[Test]
    public function for_model_falls_back_when_only_column_resolver_is_bound(): void
    {
        $container = new Container;
        $container->bind(ColumnSemanticsResolver::class, fn (): ColumnSemanticsResolver => new NullColumnSemanticsResolver);
        Container::setInstance($container);

        $model = new class extends Model {};
        $evaluator = PredicateEvaluator::forModel($model);

        $attrs = $this->idAttribute(1);

        self::assertSame(
            EvaluationResult::Match,
            $evaluator->evaluate($attrs, new InNode('id', [1, 2], false)),
        );
    }

    #[Test]
    public function model_aware_evaluator_invokes_column_resolver_and_caches_per_column(): void
    {
        $spy = new class implements ColumnSemanticsResolver
        {
            /** @var list<string> */
            public array $calls = [];

            #[\Override]
            public function for(Model $model, string $column): ColumnSemantics
            {
                $this->calls[] = $column;

                return ColumnSemantics::unknown();
            }
        };

        $model = new class extends Model {};
        $evaluator = new PredicateEvaluator(
            semantics: new ConservativeSemantics,
            columns: $spy,
            model: $model,
        );

        $attrs = $this->idAttribute(5);

        // Two evaluations against the same column should resolve column semantics
        // only once thanks to the ??= cache; mutating ??= to = would call the
        // spy twice, and mutating !$this->model instanceof Model would skip the
        // call entirely.
        $evaluator->evaluate($attrs, new ComparisonNode('id', '=', 5));
        $evaluator->evaluate($attrs, new ComparisonNode('id', '=', 7));

        self::assertSame(['id'], $spy->calls);
    }

    private function idAttribute(int $value): AttributeKnowledge
    {
        $attrs = new AttributeKnowledge;
        $attrs->set('id', new AttributeFact(
            column: 'id',
            originalValue: $value,
            currentValue: $value,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        ));

        return $attrs;
    }
}
