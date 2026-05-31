<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Knowledge;

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
    public function record_from_model_marks_facts_clean(): void
    {
        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->setRawAttributes(['id' => 1, 'name' => 'Alice'], true);

        $ak = new AttributeKnowledge;
        $ak->recordFromModel($model, isFullSelect: true);

        $fact = $ak->get('name');
        self::assertNotNull($fact);
        self::assertFalse($fact->isDirty, 'Hydrated facts must start as clean (isDirty=false).');
        self::assertSame(FactConfidence::Certain, $fact->confidence);
        self::assertSame(FactSource::HydratedFromDatabase, $fact->source);
    }

    #[Test]
    public function sync_from_model_continues_past_facts_absent_from_attributes(): void
    {
        $ak = new AttributeKnowledge;
        $ak->set('ghost', new AttributeFact(
            column: 'ghost',
            originalValue: 'old',
            currentValue: 'old',
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        ));
        $ak->set('name', new AttributeFact(
            column: 'name',
            originalValue: 'Alice',
            currentValue: 'Alice',
            isDirty: false,
            confidence: FactConfidence::Certain,
            source: FactSource::HydratedFromDatabase,
        ));

        $model = new class extends Model
        {
            protected $guarded = [];
        };
        $model->setRawAttributes(['name' => 'Alice'], true);
        $model->setAttribute('name', 'Bob');

        $ak->syncFromModel($model);

        $nameFact = $ak->get('name');
        self::assertNotNull($nameFact);
        self::assertSame(
            'Bob',
            $nameFact->currentValue,
            'syncFromModel must continue past facts missing from attributes, not break out of the loop.',
        );
    }
}
