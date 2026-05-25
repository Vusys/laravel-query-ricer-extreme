<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Vusys\QueryRicerExtreme\Driver\ColumnSemantics;
use Vusys\QueryRicerExtreme\Driver\ColumnSemanticsResolver;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;

final class SchemaDiscovery implements ColumnSemanticsResolver
{
    /** @var array<string, list<list<string>>> */
    private array $uniqueIndexCache = [];

    /** @var array<string, array<string, ColumnSemantics>> */
    private array $columnSemanticsCache = [];

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<list<string>>
     */
    public function uniqueIndexesFor(string $modelClass): array
    {
        if (config('query-ricer-extreme.schema_discovery.enabled', true) === false) {
            return [];
        }

        /** @var Model $instance */
        $instance = new $modelClass;
        $connectionName = $instance->getConnection()->getName();
        $table = $instance->getTable();
        $cacheKey = $this->cacheKey($modelClass, $connectionName, $table);

        if (isset($this->uniqueIndexCache[$cacheKey])) {
            return $this->uniqueIndexCache[$cacheKey];
        }

        try {
            $indexes = $this->introspectIndexes($connectionName, $table);
        } catch (Throwable) {
            return $this->uniqueIndexCache[$cacheKey] = [];
        }

        $unique = [];

        foreach ($indexes as $index) {
            if (($index['unique'] ?? false) !== true) {
                continue;
            }
            if (($index['primary'] ?? false) === true) {
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

            foreach ($columns as $col) {
                if (! is_string($col)) {
                    continue 2;
                }
            }

            /** @var list<string> $columns */
            $unique[] = $columns;
        }

        return $this->uniqueIndexCache[$cacheKey] = $unique;
    }

    #[\Override]
    public function for(Model $model, string $column): ColumnSemantics
    {
        $semantics = $this->columnSemanticsForModel($model);

        return $semantics[$column] ?? ColumnSemantics::unknown();
    }

    public function flush(): void
    {
        $this->uniqueIndexCache = [];
        $this->columnSemanticsCache = [];
    }

    /**
     * @return array<string, ColumnSemantics>
     */
    private function columnSemanticsForModel(Model $model): array
    {
        if (config('query-ricer-extreme.schema_discovery.enabled', true) === false) {
            return [];
        }

        $connection = $model->getConnection();
        $connectionName = $connection->getName();
        $table = $model->getTable();
        $cacheKey = $this->cacheKey($model::class, $connectionName, $table);

        if (isset($this->columnSemanticsCache[$cacheKey])) {
            return $this->columnSemanticsCache[$cacheKey];
        }

        $driver = $connection->getDriverName();

        try {
            $columns = $this->introspectColumns($connectionName, $table);
        } catch (Throwable) {
            return $this->columnSemanticsCache[$cacheKey] = [];
        }

        $configMode = $this->configuredStringMode($driver);
        $semantics = [];

        foreach ($columns as $col) {
            $name = $col['name'] ?? null;

            if (! is_string($name)) {
                continue;
            }

            $typeName = is_string($col['type_name'] ?? null) ? strtolower($col['type_name']) : '';
            $collation = is_string($col['collation'] ?? null) ? $col['collation'] : null;

            $semantics[$name] = new ColumnSemantics(
                type: $this->mapType($typeName),
                collation: $collation,
                stringComparison: $this->resolveStringMode($driver, $typeName, $collation, $configMode),
            );
        }

        return $this->columnSemanticsCache[$cacheKey] = $semantics;
    }

    private function cacheKey(string $modelClass, ?string $connectionName, string $table): string
    {
        return $modelClass.'@'.($connectionName ?? '__default__').':'.$table;
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
        return $this->silently($connectionName, fn (): array => Schema::connection($connectionName)->getIndexes($table));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function introspectColumns(?string $connectionName, string $table): array
    {
        return $this->silently($connectionName, fn (): array => Schema::connection($connectionName)->getColumns($table));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function silently(?string $connectionName, \Closure $callback): mixed
    {
        $connection = DB::connection($connectionName);
        $dispatcher = $connection->getEventDispatcher();

        if ($dispatcher !== null) {
            $connection->unsetEventDispatcher();
        }

        try {
            return $callback();
        } finally {
            if ($dispatcher !== null) {
                $connection->setEventDispatcher($dispatcher);
            }
        }
    }

    private function mapType(string $typeName): ColumnType
    {
        return match (true) {
            $typeName === '' => ColumnType::Unknown,
            in_array($typeName, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint', 'int2', 'int4', 'int8'], true) => ColumnType::Integer,
            in_array($typeName, ['float', 'double', 'real', 'numeric', 'decimal'], true) => ColumnType::Float,
            in_array($typeName, ['bool', 'boolean'], true) => ColumnType::Boolean,
            $typeName === 'uuid' => ColumnType::Uuid,
            $typeName === 'date' => ColumnType::Date,
            in_array($typeName, ['datetime', 'timestamp', 'timestamptz'], true) => ColumnType::DateTime,
            in_array($typeName, ['json', 'jsonb'], true) => ColumnType::Json,
            in_array($typeName, ['blob', 'bytea', 'binary', 'varbinary'], true) => ColumnType::Binary,
            $this->isStringType($typeName) => ColumnType::String,
            default => ColumnType::Unknown,
        };
    }

    private function isStringType(string $typeName): bool
    {
        return str_contains($typeName, 'char')
            || str_contains($typeName, 'text')
            || $typeName === 'citext'
            || $typeName === 'enum';
    }

    /**
     * @return 'database_collation'|'php_strict'|'conservative_unknown'
     */
    private function configuredStringMode(string $driver): string
    {
        $value = config('query-ricer-extreme.database_semantics.'.$driver.'.string_comparisons', 'conservative_unknown');

        return match ($value) {
            'database_collation', 'php_strict', 'conservative_unknown' => $value,
            default => 'conservative_unknown',
        };
    }

    /**
     * @param  'database_collation'|'php_strict'|'conservative_unknown'  $configMode
     */
    private function resolveStringMode(string $driver, string $typeName, ?string $collation, string $configMode): StringComparisonMode
    {
        if (! $this->isStringType($typeName) && $typeName !== '') {
            return StringComparisonMode::Unknown;
        }

        if ($configMode === 'php_strict') {
            return StringComparisonMode::CaseSensitive;
        }

        if ($configMode === 'conservative_unknown') {
            return StringComparisonMode::Unknown;
        }

        // database_collation
        if ($typeName === 'citext') {
            return StringComparisonMode::CaseInsensitive;
        }

        if (is_string($collation) && $collation !== '') {
            return $this->modeFromCollation($collation);
        }

        return match ($driver) {
            'sqlite', 'pgsql' => StringComparisonMode::CaseSensitive,
            default => StringComparisonMode::Unknown,
        };
    }

    private function modeFromCollation(string $collation): StringComparisonMode
    {
        $lower = strtolower($collation);

        if (str_ends_with($lower, '_bin') || str_contains($lower, '_cs')) {
            return StringComparisonMode::CaseSensitive;
        }

        if (str_contains($lower, '_ci') || str_contains($lower, '_ai')) {
            return StringComparisonMode::CaseInsensitive;
        }

        return StringComparisonMode::Unknown;
    }
}
