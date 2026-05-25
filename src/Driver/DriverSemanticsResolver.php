<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Illuminate\Database\Connection;

/**
 * Maps an Eloquent connection's driver name to the appropriate DriverSemantics
 * profile. Unknown drivers fall back to {@see ConservativeSemantics}, which
 * never silently downgrades to PHP loose equality.
 */
final class DriverSemanticsResolver
{
    /** @var array<string, DriverSemantics> */
    private array $cache = [];

    public function forConnection(?Connection $connection): DriverSemantics
    {
        $driver = $connection?->getDriverName();

        return $this->forDriverName($driver);
    }

    public function forDriverName(?string $driver): DriverSemantics
    {
        $key = $driver ?? '__unknown__';

        return $this->cache[$key] ?? $this->cache[$key] = $this->build($driver);
    }

    private function build(?string $driver): DriverSemantics
    {
        return match ($driver) {
            'sqlite' => new SqliteSemantics,
            'mysql' => new MySqlSemantics,
            'mariadb' => new MariaDbSemantics,
            'pgsql' => new PostgresSemantics,
            default => new ConservativeSemantics,
        };
    }
}
