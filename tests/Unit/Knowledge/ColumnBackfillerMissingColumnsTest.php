<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Knowledge;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Knowledge\ColumnBackfiller;
use Vusys\QueryRicerExtreme\Knowledge\RelationKnowledge;
use Vusys\QueryRicerExtreme\Store\IdentityEntry;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

final class ColumnBackfillerMissingColumnsTest extends TestCase
{
    private ColumnBackfiller $backfiller;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->backfiller = new ColumnBackfiller(new IdentityMapStore);
    }

    #[Test]
    public function reports_missing_columns_for_partial_entry(): void
    {
        $entry = $this->makeEntry(['id' => 1, 'name' => 'Alice']);

        $missing = $this->backfiller->missingColumns($entry, ['id', 'email', 'active']);

        $this->assertSame(['email', 'active'], $missing);
    }

    #[Test]
    public function reports_no_missing_when_all_known(): void
    {
        $entry = $this->makeEntry(['id' => 1, 'name' => 'Alice', 'email' => 'a@b']);

        $missing = $this->backfiller->missingColumns($entry, ['id', 'name']);

        $this->assertSame([], $missing);
    }

    #[Test]
    public function star_request_returns_empty_missing(): void
    {
        $entry = $this->makeEntry(['id' => 1]);

        $this->assertSame([], $this->backfiller->missingColumns($entry, ['*']));
        $this->assertSame([], $this->backfiller->missingColumns($entry, ['id', '*']));
    }

    #[Test]
    public function empty_request_returns_empty_missing(): void
    {
        $entry = $this->makeEntry(['id' => 1]);

        $this->assertSame([], $this->backfiller->missingColumns($entry, []));
    }

    /** @param array<string, mixed> $facts */
    private function makeEntry(array $facts): IdentityEntry
    {
        $attributes = new AttributeKnowledge;
        foreach ($facts as $col => $val) {
            $attributes->set($col, new AttributeFact(
                column: $col,
                originalValue: $val,
                currentValue: $val,
                isDirty: false,
                confidence: FactConfidence::Certain,
                source: FactSource::HydratedFromDatabase,
            ));
        }

        return new IdentityEntry(
            connection: 'default',
            modelClass: Model::class,
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: 1,
            scopeFingerprint: 'default',
            model: new class extends Model {},
            attributes: $attributes,
            relations: new RelationKnowledge,
            state: LifecycleState::Exists,
            version: 1,
        );
    }
}
