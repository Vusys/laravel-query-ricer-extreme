<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;

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

    #[Test]
    public function merge_from_saved_creates_fact_for_column_not_previously_tracked(): void
    {
        $knowledge = new AttributeKnowledge;
        // Only 'id' is tracked initially.
        $knowledge->set('id', $this->makeFact('id', 1));

        $model = new class extends Model
        {
            public function getAttributes(): array
            {
                return ['id' => 1, 'email' => 'alice@example.com'];
            }
        };

        $knowledge->mergeFromSaved($model);

        // 'email' was not in facts before — mergeFromSaved must create it.
        $fact = $knowledge->get('email');
        $this->assertNotNull($fact);
        $this->assertSame('alice@example.com', $fact->currentValue);
        $this->assertSame('alice@example.com', $fact->originalValue);
        $this->assertFalse($fact->isDirty);
        $this->assertSame(FactConfidence::Certain, $fact->confidence);
        $this->assertSame(FactSource::HydratedFromDatabase, $fact->source);
    }

    #[Test]
    public function satisfies_mixed_wildcard_in_list_returns_false_when_not_all_columns_known(): void
    {
        // satisfies(['*', 'id']) — the wildcard element short-circuits to allColumnsKnown.
        $knowledge = new AttributeKnowledge;
        $knowledge->set('id', $this->makeFact('id', 1));
        $knowledge->allColumnsKnown = false;

        $this->assertFalse($knowledge->satisfies(['*', 'id']));
    }

    #[Test]
    public function sync_from_model_skips_columns_absent_from_model_attributes(): void
    {
        $knowledge = new AttributeKnowledge;
        $fact = $this->makeFact('phantom', 'original');
        $knowledge->set('phantom', $fact);

        // Model whose getAttributes() does not include 'phantom'.
        $model = new class extends Model
        {
            public function getAttributes(): array
            {
                return ['id' => 1];
            }

            public function isDirty($attributes = null): bool
            {
                return false;
            }
        };

        $knowledge->syncFromModel($model);

        // 'phantom' not in model attributes — fact must be unchanged.
        $this->assertSame('original', $knowledge->get('phantom')?->currentValue);
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
