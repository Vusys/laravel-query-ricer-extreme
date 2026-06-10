<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * A partial-column query (pluck / select(subset)) on a model that is already
 * fully cached must not downgrade the cached instance to the narrow column set
 * while still claiming all columns are known — a later full read would then
 * serve a model missing attributes, diverging from SQL.
 */
final class PartialSelectDowngradeTest extends TestCase
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
    public function pluck_does_not_downgrade_a_fully_cached_model(): void
    {
        $user = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id); // fully cached

        User::query()->pluck('id'); // partial-column query over the same row

        $served = User::query()->whereKey($user->id)->first();
        $this->assertNotNull($served);
        $this->assertSame('Alice', $served->name, 'a partial pluck must not strip the cached model of other columns');
        $this->assertSame('alice@example.com', $served->email);
    }

    #[Test]
    public function select_subset_does_not_downgrade_a_fully_cached_model(): void
    {
        $user = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::find($user->id);

        User::query()->select('id')->get();

        $served = User::query()->whereKey($user->id)->first();
        $this->assertSame('Alice', $served?->name);
    }

    #[Test]
    public function unique_key_serve_after_partial_select_matches_oracle(): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['name']]],
        ]]);

        $a = User::factory()->create(['name' => 'ua', 'email' => 'ua@example.com']);
        $b = User::factory()->create(['name' => 'ub', 'email' => 'ub@example.com']);
        User::factory()->create(['name' => 'uc', 'email' => 'uc@example.com']);

        // Warm full entries, then run a partial-column sweep over the same rows.
        User::find($a->id);
        User::find($b->id);
        User::query()->pluck('id');

        $name = User::where('name', 'ub')->first()?->name;
        $oracle = IdentityMap::disabled(fn (): ?string => User::where('name', 'ub')->first()?->name);

        $this->assertSame($oracle, $name);
        $this->assertSame('ub', $name);
    }
}
