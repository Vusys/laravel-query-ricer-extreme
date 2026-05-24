<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class SchemaDiscovery
{
    /** @var array<class-string<Model>, list<list<string>>> */
    private array $cache = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<list<string>>
     */
    public function uniqueIndexesFor(string $modelClass): array
    {
        if (config('query-ricer-extreme.schema_discovery.enabled', true) === false) {
            return [];
        }

        if (isset($this->cache[$modelClass])) {
            return $this->cache[$modelClass];
        }

        /** @var Model $instance */
        $instance = new $modelClass;
        $connectionName = $instance->getConnectionName();
        $table = $instance->getTable();

        try {
            $indexes = $this->introspectIndexes($connectionName, $table);
        } catch (Throwable) {
            return $this->cache[$modelClass] = [];
        }

        $unique = [];

        foreach ($indexes as $index) {
            $isUnique = (bool) ($index['unique'] ?? false);
            $isPrimary = (bool) ($index['primary'] ?? false);
            if (! $isUnique) {
                continue;
            }
            if ($isPrimary) {
                continue;
            }

            $columns = $index['columns'] ?? null;
            if (! is_array($columns)) {
                continue;
            }
            if ($columns === []) {
                continue;
            }
            if (! array_is_list($columns)) {
                continue;
            }

            $stringColumns = array_values(array_filter($columns, is_string(...)));
            if ($stringColumns === []) {
                continue;
            }
            if (count($stringColumns) !== count($columns)) {
                continue;
            }

            $unique[] = $stringColumns;
        }

        return $this->cache[$modelClass] = $unique;
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Run schema introspection without firing QueryExecuted events, so user-facing
     * query counters (DB::listen) are unaffected by our internal PRAGMA / SHOW INDEX
     * traffic.
     *
     * @return list<array<string, mixed>>
     */
    private function introspectIndexes(?string $connectionName, string $table): array
    {
        $connection = DB::connection($connectionName);
        $dispatcher = $connection->getEventDispatcher();

        if ($dispatcher !== null) {
            $connection->unsetEventDispatcher();
        }

        try {
            return Schema::connection($connectionName)->getIndexes($table);
        } finally {
            if ($dispatcher !== null) {
                $connection->setEventDispatcher($dispatcher);
            }
        }
    }
}
