<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\AttributeFact;
use Vusys\QueryRicerExtreme\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;

final class AttributeKnowledgeTest extends TestCase
{
    #[Test]
    public function satisfies_wildcard_requires_all_columns_known(): void
    {
        $knowledge = new AttributeKnowledge;
        $knowledge->allColumnsKnown = false;

        $this->assertFalse($knowledge->satisfies(['*']));

        $knowledge->allColumnsKnown = true;

        $this->assertTrue($knowledge->satisfies(['*']));
    }

    #[Test]
    public function satisfies_specific_columns_checks_facts(): void
    {
        $knowledge = new AttributeKnowledge;
        $knowledge->set('id', $this->makeFact('id', 1));
        $knowledge->set('name', $this->makeFact('name', 'Alice'));

        $this->assertTrue($knowledge->satisfies(['id', 'name']));
        $this->assertFalse($knowledge->satisfies(['id', 'name', 'email']));
    }

    #[Test]
    public function satisfies_returns_false_when_any_column_missing(): void
    {
        $knowledge = new AttributeKnowledge;
        $knowledge->set('id', $this->makeFact('id', 1));

        $this->assertFalse($knowledge->satisfies(['id', 'email']));
        $this->assertFalse($knowledge->satisfies(['email']));
        $this->assertTrue($knowledge->satisfies(['id']));
    }

    #[Test]
    public function knows_column_returns_correctly(): void
    {
        $knowledge = new AttributeKnowledge;

        $this->assertFalse($knowledge->knows('id'));

        $knowledge->set('id', $this->makeFact('id', 1));

        $this->assertTrue($knowledge->knows('id'));
    }

    #[Test]
    public function get_returns_fact_or_null(): void
    {
        $knowledge = new AttributeKnowledge;
        $fact = $this->makeFact('id', 1);
        $knowledge->set('id', $fact);

        $this->assertSame($fact, $knowledge->get('id'));
        $this->assertNull($knowledge->get('missing'));
    }

    #[Test]
    public function satisfies_empty_columns_returns_true(): void
    {
        $knowledge = new AttributeKnowledge;

        $this->assertTrue($knowledge->satisfies([]));
    }

    private function makeFact(string $column, mixed $value): AttributeFact
    {
        return new AttributeFact(
            column: $column,
            originalValue: $value,
            currentValue: $value,
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        );
    }
}
