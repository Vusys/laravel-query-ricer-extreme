<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Driver;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Driver\DriverSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\MariaDbSemantics;
use Vusys\QueryRicerExtreme\Driver\MySqlSemantics;
use Vusys\QueryRicerExtreme\Driver\PostgresSemantics;
use Vusys\QueryRicerExtreme\Driver\SqliteSemantics;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class DriverSemanticsIntegrationTest extends TestCase
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
    public function resolver_picks_profile_for_active_connection(): void
    {
        $resolver = resolve(DriverSemanticsResolver::class);
        $semantics = $resolver->forConnection(DB::connection());

        $expected = match (DB::connection()->getDriverName()) {
            'sqlite' => SqliteSemantics::class,
            'mysql' => MySqlSemantics::class,
            'mariadb' => MariaDbSemantics::class,
            'pgsql' => PostgresSemantics::class,
            default => self::fail('Unsupported driver in test'),
        };

        self::assertInstanceOf($expected, $semantics);
    }

    #[Test]
    public function bounded_keyset_with_byte_identical_predicate_serves_from_memory(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        $this->store->flush();

        User::find($alice->id);
        User::find($bob->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('name', 'Alice')->get();

        self::assertSame(0, $queryCount, 'Byte-identical string equality must be confidently resolved across every driver');
        self::assertCount(1, $result);
        self::assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function case_different_predicate_resolves_per_driver_default(): void
    {
        $driver = DB::connection()->getDriverName();
        config()->set('query-ricer-extreme.database_semantics.'.$driver.'.string_comparisons', 'conservative_unknown');
        resolve(SchemaDiscovery::class)->flush();

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $this->store->flush();

        User::find($alice->id);

        $evaluator = PredicateEvaluator::forModel($alice);
        $aliceEntry = $this->store->find(
            connection: $alice->getConnectionName() ?? 'default',
            modelClass: User::class,
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: $alice->id,
            fingerprint: 'soft-delete:default',
        );
        self::assertNotNull($aliceEntry);

        $predicate = new AndNode([new ComparisonNode('name', '=', 'ALICE')]);
        $result = $evaluator->evaluate($aliceEntry->attributes, $predicate);

        // Drivers whose default collation is case-sensitive (SQLite BINARY,
        // Postgres) can confidently Reject. MySQL/MariaDB use case-insensitive
        // collations by default — without metadata, the answer is Unknown.
        $expected = match ($driver) {
            'sqlite', 'pgsql' => EvaluationResult::Reject,
            default => EvaluationResult::Unknown,
        };

        self::assertSame(
            $expected,
            $result,
            "Driver $driver must resolve case-different equality according to its native collation default",
        );
    }

    #[Test]
    public function php_strict_mode_resolves_case_difference_as_reject(): void
    {
        $driver = DB::connection()->getDriverName();
        config()->set('query-ricer-extreme.database_semantics.'.$driver.'.string_comparisons', 'php_strict');
        resolve(SchemaDiscovery::class)->flush();

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        $this->store->flush();

        User::find($alice->id);

        $evaluator = PredicateEvaluator::forModel($alice);
        $aliceEntry = $this->store->find(
            connection: $alice->getConnectionName() ?? 'default',
            modelClass: User::class,
            table: 'users',
            primaryKeyName: 'id',
            primaryKeyValue: $alice->id,
            fingerprint: 'soft-delete:default',
        );
        self::assertNotNull($aliceEntry);

        $predicate = new AndNode([new ComparisonNode('name', '=', 'ALICE')]);
        $result = $evaluator->evaluate($aliceEntry->attributes, $predicate);

        // php_strict treats string comparison as case-sensitive across drivers,
        // and PHP `===` says 'Alice' !== 'ALICE'.
        self::assertSame(EvaluationResult::Reject, $result);
    }
}
