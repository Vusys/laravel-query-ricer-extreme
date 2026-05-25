<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Driver;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Driver\ConservativeSemantics;
use Vusys\QueryRicerExtreme\Driver\DriverSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\MariaDbSemantics;
use Vusys\QueryRicerExtreme\Driver\MySqlSemantics;
use Vusys\QueryRicerExtreme\Driver\PostgresSemantics;
use Vusys\QueryRicerExtreme\Driver\SqliteSemantics;

final class DriverSemanticsResolverTest extends TestCase
{
    #[Test]
    public function maps_each_driver_name(): void
    {
        $r = new DriverSemanticsResolver;
        self::assertInstanceOf(SqliteSemantics::class, $r->forDriverName('sqlite'));
        self::assertInstanceOf(MySqlSemantics::class, $r->forDriverName('mysql'));
        self::assertInstanceOf(MariaDbSemantics::class, $r->forDriverName('mariadb'));
        self::assertInstanceOf(PostgresSemantics::class, $r->forDriverName('pgsql'));
    }

    #[Test]
    public function unknown_driver_falls_back_to_conservative(): void
    {
        $r = new DriverSemanticsResolver;
        self::assertInstanceOf(ConservativeSemantics::class, $r->forDriverName('oracle'));
        self::assertInstanceOf(ConservativeSemantics::class, $r->forDriverName(null));
    }

    #[Test]
    public function results_are_cached_per_driver_name(): void
    {
        $r = new DriverSemanticsResolver;
        self::assertSame($r->forDriverName('sqlite'), $r->forDriverName('sqlite'));
        self::assertSame($r->forDriverName(null), $r->forDriverName(null));
    }

    #[Test]
    public function null_connection_falls_back_to_conservative(): void
    {
        $r = new DriverSemanticsResolver;
        self::assertInstanceOf(ConservativeSemantics::class, $r->forConnection(null));
    }
}
