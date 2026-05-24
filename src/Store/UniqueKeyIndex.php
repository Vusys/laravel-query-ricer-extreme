<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;

final class UniqueKeyIndex
{
    /** @var array<string, string> unique-key fingerprint → primary map key */
    private array $index = [];

    /** @var array<string, true> */
    private array $absent = [];

    /** @var array<string, list<list<string>>> indexes registered at runtime (e.g. by schema discovery) */
    private array $registered = [];

    /** @var array<string, true> classes that have completed runtime discovery */
    private array $discoveredClasses = [];

    public function index(IdentityEntry $entry, string $mapKey): void
    {
        foreach ($this->uniqueIndexesForModelClass($entry->modelClass) as $columns) {
            $values = [];

            foreach ($columns as $column) {
                $fact = $entry->attributes->get($column);

                if (! $fact instanceof AttributeFact) {
                    continue 2;
                }

                $values[$column] = $fact->originalValue;
            }

            ksort($values);
            $fp = $this->makeFingerprint($entry->connection, $entry->modelClass, $entry->table, $entry->scopeFingerprint, $values);
            $this->index[$fp] = $mapKey;
            unset($this->absent[$fp]);
        }
    }

    public function findMapKey(string $uniqueFingerprint): ?string
    {
        return $this->index[$uniqueFingerprint] ?? null;
    }

    public function evict(string $uniqueFingerprint): void
    {
        unset($this->index[$uniqueFingerprint]);
    }

    public function isAbsent(string $uniqueFingerprint): bool
    {
        return isset($this->absent[$uniqueFingerprint]);
    }

    public function recordAbsent(string $uniqueFingerprint): void
    {
        $this->absent[$uniqueFingerprint] = true;
    }

    public function flush(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            $this->index = [];
            $this->absent = [];
            $this->registered = [];
            $this->discoveredClasses = [];

            return;
        }

        foreach (array_keys($this->index) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->index[$key]);
            }
        }

        foreach (array_keys($this->absent) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->absent[$key]);
            }
        }

        unset($this->registered[$modelClass], $this->discoveredClasses[$modelClass]);
    }

    /** @return list<list<string>> */
    public function uniqueIndexesForModelClass(string $modelClass): array
    {
        $rawConfig = config("query-ricer-extreme.models.{$modelClass}.unique");

        $result = [];
        $seen = [];

        if (is_array($rawConfig)) {
            foreach ($rawConfig as $indexEntry) {
                if (! is_array($indexEntry)) {
                    continue;
                }

                $columns = [];

                foreach ($indexEntry as $col) {
                    if (is_string($col)) {
                        $columns[] = $col;
                    }
                }

                if ($columns === []) {
                    continue;
                }

                $key = $this->columnSetKey($columns);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $result[] = $columns;
            }
        }

        foreach ($this->registered[$modelClass] ?? [] as $columns) {
            $key = $this->columnSetKey($columns);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $columns;
        }

        return $result;
    }

    /**
     * Register a discovered unique-index column set for the given model class.
     *
     * @param  list<string>  $columns
     */
    public function register(string $modelClass, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $existing = $this->registered[$modelClass] ?? [];
        $key = $this->columnSetKey($columns);

        foreach ($existing as $known) {
            if ($this->columnSetKey($known) === $key) {
                return;
            }
        }

        $existing[] = $columns;
        $this->registered[$modelClass] = $existing;
    }

    public function hasDiscovered(string $modelClass): bool
    {
        return isset($this->discoveredClasses[$modelClass]);
    }

    public function markDiscovered(string $modelClass): void
    {
        $this->discoveredClasses[$modelClass] = true;
    }

    /** @param list<string> $columns */
    private function columnSetKey(array $columns): string
    {
        $sorted = $columns;
        sort($sorted);

        return implode("\0", $sorted);
    }

    /** @param array<string, mixed> $values already ksorted */
    public function makeFingerprint(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $values,
    ): string {
        return "{$connection}|{$modelClass}|{$table}|{$fingerprint}|UQ:".serialize($values);
    }

    /** @return array{unique_index: int, unique_absent: int} */
    public function debugStats(): array
    {
        return [
            'unique_index' => count($this->index),
            'unique_absent' => count($this->absent),
        ];
    }
}
