<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;

final class IdentityMapStore
{
    /** @var array<string, IdentityEntry> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $absent = [];

    /** @var array<string, string> unique-key fingerprint → map key */
    private array $uniqueIndex = [];

    /** @var array<string, true> */
    private array $uniqueAbsent = [];

    private bool $disabled = false;

    private bool $capturing = false;

    /** @var list<Explanation> */
    private array $captured = [];

    public function remember(Model $model, bool $allColumnsKnown = false): void
    {
        if ($this->disabled) {
            return;
        }

        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        if (! $model->exists) {
            return;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        if (isset($this->entries[$mapKey])) {
            $entry = $this->entries[$mapKey];
            $entry->model = $model;
            $entry->version++;
            $entry->attributes->recordFromModel($model, $allColumnsKnown);
        } else {
            $attributes = new AttributeKnowledge;
            $attributes->recordFromModel($model, $allColumnsKnown);

            $this->entries[$mapKey] = new IdentityEntry(
                connection: $model->getConnectionName() ?? 'default',
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $key,
                scopeFingerprint: $fingerprint,
                model: $model,
                attributes: $attributes,
                relations: new RelationKnowledge,
                state: LifecycleState::Exists,
                version: 1,
            );
        }

        unset($this->absent[$mapKey]);
        $this->rememberByUniqueKeys($this->entries[$mapKey], $mapKey);
    }

    public function markAllColumnsKnown(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        if (isset($this->entries[$mapKey])) {
            $this->entries[$mapKey]->attributes->allColumnsKnown = true;
        }
    }

    public function afterSaved(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        if (isset($this->entries[$mapKey])) {
            $entry = $this->entries[$mapKey];
            $entry->model = $model;
            $entry->state = LifecycleState::Exists;
            $entry->version++;
            $entry->attributes->mergeFromSaved($model);
            $entry->attributes->allColumnsKnown = true;
            $this->rememberByUniqueKeys($entry, $mapKey);
        } else {
            $this->remember($model, true);
        }

        unset($this->absent[$mapKey]);
    }

    public function afterDeleted(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            // deleted_at is now set, so fromModel() returns 'with-trashed' — but the
            // entry was stored before deletion with the non-trashed fingerprint.
            $mapKey = $this->makeKeyFromParts(
                $model->getConnectionName() ?? 'default',
                $model::class,
                $model->getTable(),
                $model->getKeyName(),
                $key,
                'soft-delete:default',
            );

            if (isset($this->entries[$mapKey])) {
                $entry = $this->entries[$mapKey];
                $entry->state = LifecycleState::SoftDeleted;
                $entry->version++;
                $entry->attributes->mergeFromSaved($model);
            }
        } else {
            $fingerprint = ScopeFingerprinter::fromModel($model);
            $mapKey = $this->makeKey($model, $key, $fingerprint);

            if (isset($this->entries[$mapKey])) {
                $this->entries[$mapKey]->state = LifecycleState::Deleted;
                $this->entries[$mapKey]->version++;
            }
        }
    }

    public function afterForceDeleted(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        // deleted_at is set by the time this fires; use the pre-deletion fingerprint
        $mapKey = $this->makeKeyFromParts(
            $model->getConnectionName() ?? 'default',
            $model::class,
            $model->getTable(),
            $model->getKeyName(),
            $key,
            'soft-delete:default',
        );

        if (isset($this->entries[$mapKey])) {
            $this->entries[$mapKey]->state = LifecycleState::Deleted;
            $this->entries[$mapKey]->version++;
        }
    }

    public function find(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): ?IdentityEntry {
        $mapKey = $this->makeKeyFromParts(
            $connection,
            $modelClass,
            $table,
            $primaryKeyName,
            $primaryKeyValue,
            $fingerprint,
        );

        return $this->entries[$mapKey] ?? null;
    }

    public function isAbsent(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): bool {
        $mapKey = $this->makeKeyFromParts(
            $connection,
            $modelClass,
            $table,
            $primaryKeyName,
            $primaryKeyValue,
            $fingerprint,
        );

        return isset($this->absent[$mapKey]);
    }

    public function recordAbsent(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): void {
        $mapKey = $this->makeKeyFromParts(
            $connection,
            $modelClass,
            $table,
            $primaryKeyName,
            $primaryKeyValue,
            $fingerprint,
        );

        $this->absent[$mapKey] = true;
    }

    public function forget(Model $model): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        unset($this->entries[$mapKey], $this->absent[$mapKey]);
    }

    public function flush(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            $this->entries = [];
            $this->absent = [];
            $this->uniqueIndex = [];
            $this->uniqueAbsent = [];

            return;
        }

        foreach (array_keys($this->entries) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->entries[$key]);
            }
        }

        foreach (array_keys($this->absent) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->absent[$key]);
            }
        }

        foreach (array_keys($this->uniqueIndex) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->uniqueIndex[$key]);
            }
        }

        foreach (array_keys($this->uniqueAbsent) as $key) {
            if (str_contains($key, "|{$modelClass}|")) {
                unset($this->uniqueAbsent[$key]);
            }
        }
    }

    /**
     * Partition a bounded key set into memory hits, absent keys, and unknown keys.
     *
     * @param  list<int|string>  $keys
     * @param  list<string>  $columns
     * @return array{0: array<int|string, IdentityEntry>, 1: list<int|string>, 2: list<int|string>}
     */
    public function partitionKeySet(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        array $keys,
        string $fingerprint,
        array $columns,
    ): array {
        $hits = [];
        $absentKeys = [];
        $unknownKeys = [];

        foreach ($keys as $key) {
            $entry = $this->find($connection, $modelClass, $table, $primaryKeyName, $key, $fingerprint);

            if ($entry instanceof IdentityEntry) {
                if ($entry->state === LifecycleState::Exists && $entry->attributes->satisfies($columns)) {
                    $hits[$key] = $entry;
                } elseif ($entry->state === LifecycleState::Exists) {
                    $unknownKeys[] = $key;
                } else {
                    $absentKeys[] = $key;
                }
            } elseif ($this->isAbsent($connection, $modelClass, $table, $primaryKeyName, $key, $fingerprint)) {
                $absentKeys[] = $key;
            } else {
                $unknownKeys[] = $key;
            }
        }

        return [$hits, $absentKeys, $unknownKeys];
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function disabled(Closure $callback): mixed
    {
        $previous = $this->disabled;
        $this->disabled = true;

        try {
            return $callback();
        } finally {
            $this->disabled = $previous;
        }
    }

    /** @return list<Explanation> */
    public function explain(Closure $callback): array
    {
        $previous = $this->capturing;
        $previousCaptured = $this->captured;
        $this->capturing = true;
        $this->captured = [];

        try {
            $callback();
        } finally {
            $this->capturing = $previous;
        }

        $result = $this->captured;
        $this->captured = $previousCaptured;

        return $result;
    }

    public function capture(Explanation $explanation): void
    {
        if ($this->capturing) {
            $this->captured[] = $explanation;
        }
    }

    public function isCapturing(): bool
    {
        return $this->capturing;
    }

    /**
     * Look up an identity entry by unique-key equality values.
     *
     * Returns null if the entry is not indexed, the index is stale, or the
     * stored attributes no longer match the queried values.
     *
     * @param  array<string, mixed>  $equalityValues  column → value (order-independent)
     */
    public function findByUniqueKey(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $equalityValues,
    ): ?IdentityEntry {
        ksort($equalityValues);
        $uniqueFp = $this->makeUniqueKeyFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        $mapKey = $this->uniqueIndex[$uniqueFp] ?? null;
        if ($mapKey === null) {
            return null;
        }

        $entry = $this->entries[$mapKey] ?? null;
        if ($entry === null) {
            unset($this->uniqueIndex[$uniqueFp]);

            return null;
        }

        foreach ($equalityValues as $column => $value) {
            $fact = $entry->attributes->get($column);
            if (! $fact instanceof AttributeFact) {
                return null;
            }
            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            if ($fact->originalValue != $value) {
                unset($this->uniqueIndex[$uniqueFp]);

                return null;
            }
        }

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $equalityValues  column → value (order-independent)
     */
    public function isAbsentByUniqueKey(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $equalityValues,
    ): bool {
        ksort($equalityValues);
        $uniqueFp = $this->makeUniqueKeyFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        return isset($this->uniqueAbsent[$uniqueFp]);
    }

    /**
     * @param  array<string, mixed>  $equalityValues  column → value (order-independent)
     */
    public function recordAbsentByUniqueKey(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $equalityValues,
    ): void {
        ksort($equalityValues);
        $uniqueFp = $this->makeUniqueKeyFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        $this->uniqueAbsent[$uniqueFp] = true;
    }

    /** @return array<string, mixed> */
    public function debugStats(): array
    {
        return [
            'entries' => count($this->entries),
            'absent' => count($this->absent),
            'unique_index' => count($this->uniqueIndex),
            'unique_absent' => count($this->uniqueAbsent),
            'disabled' => $this->disabled,
        ];
    }

    private function rememberByUniqueKeys(IdentityEntry $entry, string $mapKey): void
    {
        $uniqueIndexes = $this->uniqueIndexesForModelClass($entry->modelClass);

        foreach ($uniqueIndexes as $columns) {
            $values = [];

            foreach ($columns as $column) {
                $fact = $entry->attributes->get($column);

                if (! $fact instanceof AttributeFact) {
                    continue 2;
                }

                $values[$column] = $fact->originalValue;
            }

            ksort($values);
            $uniqueFp = $this->makeUniqueKeyFingerprint(
                $entry->connection,
                $entry->modelClass,
                $entry->table,
                $entry->scopeFingerprint,
                $values,
            );

            $this->uniqueIndex[$uniqueFp] = $mapKey;
            unset($this->uniqueAbsent[$uniqueFp]);
        }
    }

    /** @return list<list<string>> */
    public function uniqueIndexesForModelClass(string $modelClass): array
    {
        $rawConfig = config("query-ricer-extreme.models.{$modelClass}.unique");

        if (! is_array($rawConfig)) {
            return [];
        }

        $result = [];

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

            if ($columns !== []) {
                $result[] = $columns;
            }
        }

        return $result;
    }

    private function makeKey(Model $model, int|string $primaryKeyValue, string $fingerprint): string
    {
        return $this->makeKeyFromParts(
            $model->getConnectionName() ?? 'default',
            $model::class,
            $model->getTable(),
            $model->getKeyName(),
            $primaryKeyValue,
            $fingerprint,
        );
    }

    private function makeKeyFromParts(
        string $connection,
        string $modelClass,
        string $table,
        string $primaryKeyName,
        int|string $primaryKeyValue,
        string $fingerprint,
    ): string {
        return "{$connection}|{$modelClass}|{$table}|{$primaryKeyName}|{$primaryKeyValue}|{$fingerprint}";
    }

    /** @param array<string, mixed> $values already ksorted */
    private function makeUniqueKeyFingerprint(
        string $connection,
        string $modelClass,
        string $table,
        string $fingerprint,
        array $values,
    ): string {
        return "{$connection}|{$modelClass}|{$table}|{$fingerprint}|UQ:".serialize($values);
    }
}
