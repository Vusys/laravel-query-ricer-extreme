<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Gadget;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Accessors (incl. ones shadowing a real column) and appended attributes are
 * computed, not stored. The identity map must record DB values — never accessor
 * output — so predicate evaluation on a shadowed column still matches SQL, and
 * appended attributes survive a memory-served read.
 */
final class AccessorAppendsTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    #[Test]
    public function facts_record_db_value_not_accessor_output(): void
    {
        $gadget = Gadget::create(['code' => 'abc', 'qty' => 3]);
        Gadget::find($gadget->id);

        $entry = $this->store->findEntry($gadget);
        $this->assertNotNull($entry);
        $this->assertSame('abc', $entry->attributes->get('code')?->originalValue, 'fact must hold the raw DB value, not the upper-cased accessor output');

        // The accessor still transforms on read.
        $this->assertSame('ABC', $gadget->code);
    }

    #[Test]
    public function predicate_on_shadowed_column_matches_sql_from_memory(): void
    {
        $gadget = Gadget::create(['code' => 'abc', 'qty' => 3]);
        Gadget::query()->get(); // record coverage so the predicate can be evaluated in memory

        // The predicate compares against the stored DB value, so a memory-served
        // result must equal SQL — whatever the column collation decides about
        // 'abc' vs 'ABC' (case-sensitive on sqlite/pgsql, case-insensitive on a
        // default MySQL/MariaDB collation; under the conservative string mode the
        // package bails to SQL either way). The accessor output never enters in:
        // a naive implementation reading $gadget->code ('ABC') would wrongly match
        // the 'ABC' query, diverging from the oracle.
        $hit = Gadget::where('code', 'abc')->get()->pluck('id')->all();
        $miss = Gadget::where('code', 'ABC')->get()->pluck('id')->all();

        $oracleHit = IdentityMap::disabled(fn (): array => Gadget::where('code', 'abc')->get()->pluck('id')->all());
        $oracleMiss = IdentityMap::disabled(fn (): array => Gadget::where('code', 'ABC')->get()->pluck('id')->all());

        $this->assertSame($oracleHit, $hit, 'exact-case predicate must match SQL');
        $this->assertSame($oracleMiss, $miss, 'predicate must use the DB value, not the accessor output — and match SQL collation behaviour');
        $this->assertSame([$gadget->id], $hit);
    }

    #[Test]
    public function appended_attribute_present_on_memory_served_model(): void
    {
        $gadget = Gadget::create(['code' => 'abc', 'qty' => 3]);
        Gadget::find($gadget->id);

        $served = Gadget::query()->whereKey($gadget->id)->first();
        $this->assertNotNull($served);

        $array = $served->toArray();
        $this->assertArrayHasKey('label', $array, 'appended attribute must survive a memory-served read');
        $this->assertSame('G-ABC', $array['label']);
    }
}
