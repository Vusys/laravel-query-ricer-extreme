<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class AutoDiscoveryUniqueKeyTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
        config(['query-ricer-extreme.models' => []]);
    }

    private function createUser(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'active' => true]);
        $this->store->flush();

        return $user;
    }

    #[Test]
    public function discovered_unique_index_hits_memory_without_config(): void
    {
        $alice = $this->createUser('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(0, $queryCount, 'Discovered unique key must serve from memory without config');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function discovered_unique_index_returns_same_instance(): void
    {
        $alice = $this->createUser('Alice', 'alice@example.com');
        $loaded = User::find($alice->id);

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame($loaded, $result);
    }

    #[Test]
    public function discovered_unique_absence_is_tracked(): void
    {
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $first = User::where('email', 'ghost@example.com')->first();
        $this->assertNull($first);
        $this->assertSame(1, $queryCount);

        $queryCount = 0;
        $second = User::where('email', 'ghost@example.com')->first();
        $this->assertNull($second);
        $this->assertSame(0, $queryCount, 'Discovered-key absence must be tracked across calls');
    }

    #[Test]
    public function discovery_does_not_track_non_unique_columns(): void
    {
        $this->createUser('Alice', 'alice@example.com');

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $first = User::where('name', 'Alice')->first();
        $this->assertNotNull($first);
        $this->assertSame(1, $queryCount);

        $queryCount = 0;
        $second = User::where('name', 'Alice')->first();
        $this->assertNotNull($second);
        $this->assertSame(1, $queryCount, 'Non-unique column still hits SQL on every call');
    }

    // -----------------------------------------------------------------------
    // Regression — invariants from the spec
    // -----------------------------------------------------------------------

    #[Test]
    public function without_identity_map_bypasses_discovered_unique_key(): void
    {
        $alice = $this->createUser('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::withoutIdentityMap()->where('email', 'alice@example.com')->first();

        $this->assertSame(1, $queryCount, 'withoutIdentityMap must bypass discovered unique key');
        $this->assertNotNull($result);
    }

    #[Test]
    public function config_declared_unique_works_when_discovery_disabled(): void
    {
        config([
            'query-ricer-extreme.schema_discovery.enabled' => false,
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);
        $this->store->flush();

        $alice = $this->createUser('Alice', 'alice@example.com');
        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->first();

        $this->assertSame(0, $queryCount, 'Config-declared unique still serves from memory when discovery is off');
        $this->assertNotNull($result);
        $this->assertSame($alice->id, $result->id);
    }

    #[Test]
    public function disabled_discovery_returns_empty_for_known_unique_table(): void
    {
        config(['query-ricer-extreme.schema_discovery.enabled' => false]);
        resolve(SchemaDiscovery::class)->flush();

        $this->assertSame([], resolve(SchemaDiscovery::class)->uniqueIndexesFor(User::class));
    }

    #[Test]
    public function store_flush_resets_discovery_state(): void
    {
        $alice = $this->createUser('Alice', 'alice@example.com');
        User::find($alice->id);

        // Pre-flush: discovered unique index hits memory
        $hitBeforeFlush = User::where('email', 'alice@example.com')->first();
        $this->assertSame($alice->id, $hitBeforeFlush?->id);

        $this->store->flush();

        // Schema must be re-introspected after flush; first lookup misses memory and queries SQL.
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $hitAfterFlush = User::where('email', 'alice@example.com')->first();
        $this->assertNotNull($hitAfterFlush);
        $this->assertSame(1, $queryCount, 'After flush, first lookup re-hits SQL (cache wiped)');
    }

    #[Test]
    public function compound_discovered_index_serves_compound_lookup(): void
    {
        Schema::create('m15_widgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('slug');
            $table->string('label')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        try {
            $widget = new class extends Model
            {
                use HasIdentityMap;

                protected $table = 'm15_widgets';

                protected $guarded = [];
            };

            $modelClass = $widget::class;

            $created = $modelClass::create(['tenant_id' => 1, 'slug' => 'foo', 'label' => 'Foo']);
            $this->store->flush();

            $modelClass::find($created->getKey());

            $queryCount = 0;
            DB::listen(function () use (&$queryCount): void {
                $queryCount++;
            });

            $result = $modelClass::where('tenant_id', 1)->where('slug', 'foo')->first();

            $this->assertSame(0, $queryCount, 'Discovered compound key must hit memory');
            $this->assertNotNull($result);
            $this->assertSame($created->getKey(), $result->getKey());
        } finally {
            Schema::dropIfExists('m15_widgets');
        }
    }
}
