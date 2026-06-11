<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Fuzz\Support\ConnectionContext;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Two adjacent concerns:
 *
 *  - A connection with a table prefix ('app_') must round-trip through schema
 *    discovery, the unique-key index, and coverage exactly as an unprefixed one:
 *    the package stores bare table names and lets the driver grammar apply the
 *    prefix, so memory-served reads must equal SQL.
 *  - The store key includes the connection name, so the same primary key on two
 *    connections must never cross-contaminate.
 */
final class PrefixAndConnectionTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.prefixed' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'app_',
            'foreign_key_constraints' => true,
        ]]);

        Schema::connection('prefixed')->dropIfExists('users');
        Schema::connection('prefixed')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->integer('score')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Schema::connection('prefixed')->dropIfExists('users');
        DB::connection('prefixed')->disconnect();
        parent::tearDown();
    }

    #[Test]
    public function the_prefix_is_actually_applied_in_sql(): void
    {
        ConnectionContext::using('prefixed', function (): void {
            User::create(['name' => 'A', 'email' => 'a@example.com']);
        });

        // The physical table carries the prefix; the bare name does not exist.
        $this->assertTrue(Schema::connection('prefixed')->hasTable('users'), 'hasTable resolves the bare name through the prefix');
        $columns = DB::connection('prefixed')->select("SELECT name FROM sqlite_master WHERE type='table' AND name='app_users'");
        $this->assertNotSame([], $columns, 'the physical table is app_users');
    }

    #[Test]
    public function predicate_pruning_under_a_prefixed_connection_matches_sql(): void
    {
        ConnectionContext::using('prefixed', function (): void {
            $a = User::create(['name' => 'Active', 'email' => 'active@example.com', 'active' => true]);
            $b = User::create(['name' => 'Inactive', 'email' => 'inactive@example.com', 'active' => false]);

            User::find($a->id);
            User::find($b->id);

            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $ricer = User::whereKey([$a->id, $b->id])->where('active', true)->get()->pluck('name')->all();

            $this->assertSame(0, $queries, 'key-set + predicate must serve from memory even under a prefix');
            $this->assertSame(['Active'], $ricer);
        });
    }

    #[Test]
    public function unique_key_lookup_under_a_prefixed_connection_matches_sql(): void
    {
        ConnectionContext::using('prefixed', function (): void {
            $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
            User::find($user->id);

            $ricer = User::where('email', 'a@example.com')->first();
            $oracle = IdentityMap::disabled(
                static fn (): ?string => User::where('email', 'a@example.com')->first()?->name,
            );

            $this->assertNotNull($ricer);
            $this->assertSame($oracle, $ricer->name);
            $this->assertSame($user->id, $ricer->id);
        });
    }

    #[Test]
    public function the_same_primary_key_does_not_leak_across_connections(): void
    {
        $default = User::create(['name' => 'DefaultAlice', 'email' => 'd@example.com']);

        $prefixed = ConnectionContext::using('prefixed', static fn () => User::create(['name' => 'PrefixedBob', 'email' => 'p@example.com']));
        $this->assertInstanceOf(User::class, $prefixed);
        $this->assertSame($default->id, $prefixed->id, 'both rows must share a primary key for the test to be meaningful');

        // Warm both connections' entries for that shared key.
        $warmDefault = User::query()->whereKey($default->id)->first();
        $warmPrefixed = ConnectionContext::using('prefixed', static fn () => User::query()->whereKey($default->id)->first());

        $this->assertInstanceOf(User::class, $warmDefault);
        $this->assertInstanceOf(User::class, $warmPrefixed);
        $this->assertSame('DefaultAlice', $warmDefault->name);
        $this->assertSame('PrefixedBob', $warmPrefixed->name);

        // Re-read from memory on each connection — neither may serve the other's row.
        $reDefault = User::query()->whereKey($default->id)->first();
        $rePrefixed = ConnectionContext::using('prefixed', static fn () => User::query()->whereKey($default->id)->first());
        $this->assertInstanceOf(User::class, $reDefault);
        $this->assertInstanceOf(User::class, $rePrefixed);
        $this->assertSame('DefaultAlice', $reDefault->name);
        $this->assertSame('PrefixedBob', $rePrefixed->name);
    }
}
