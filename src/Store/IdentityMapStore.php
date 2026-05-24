<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\QueryRicerExtreme\Enums\EvaluationResult;
use Vusys\QueryRicerExtreme\Enums\FactConfidence;
use Vusys\QueryRicerExtreme\Enums\FactSource;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Knowledge\AttributeFact;
use Vusys\QueryRicerExtreme\Knowledge\AttributeKnowledge;
use Vusys\QueryRicerExtreme\Knowledge\RelationKnowledge;
use Vusys\QueryRicerExtreme\Predicate\PredicateEvaluator;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;

final class IdentityMapStore
{
    /** @var array<string, IdentityEntry> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $absent = [];

    private readonly UniqueKeyIndex $uniqueKeyIndex;

    private bool $disabled = false;

    private ?string $pendingFingerprint = null;

    private bool $capturing = false;

    /** @var list<Explanation> */
    private array $captured = [];

    public function __construct()
    {
        $this->uniqueKeyIndex = new UniqueKeyIndex;
    }

    public function setPendingFingerprint(?string $fingerprint): void
    {
        $this->pendingFingerprint = $fingerprint;
    }

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

        $fingerprint = $this->pendingFingerprint ?? ScopeFingerprinter::fromModel($model);
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
        $this->ensureDiscoveredFor($model::class);
        $this->uniqueKeyIndex->index($this->entries[$mapKey], $mapKey);
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
            $this->ensureDiscoveredFor($model::class);
            $this->uniqueKeyIndex->index($entry, $mapKey);
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

            // The model is definitively soft-deleted within this process; record absence
            // immediately so subsequent default-scope finds skip SQL entirely.
            $this->absent[$mapKey] = true;
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

        // afterDeleted() fires first (during forceDelete) and records absence, but
        // force-delete intentionally does not record absence — next find must hit SQL.
        unset($this->absent[$mapKey]);
    }

    public function findEntry(Model $model): ?IdentityEntry
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $fingerprint = ScopeFingerprinter::fromModel($model);
        $mapKey = $this->makeKey($model, $key, $fingerprint);

        return $this->entries[$mapKey] ?? null;
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
            $this->uniqueKeyIndex->flush();
            resolve(SchemaDiscovery::class)->flush();

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

        $this->uniqueKeyIndex->flush($modelClass);
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
                } elseif ($entry->state === LifecycleState::SoftDeleted) {
                    // We know the record is soft-deleted; default scope won't return it.
                    $absentKeys[] = $key;
                } else {
                    // Deleted (force-deleted): absence is not recorded by design — fall through to SQL.
                    $unknownKeys[] = $key;
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

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
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

        $result = [];

        try {
            $callback();
            $result = $this->captured;
        } finally {
            $this->capturing = $previous;
            $this->captured = $previousCaptured;
        }

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
        $uniqueFp = $this->uniqueKeyIndex->makeFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        $mapKey = $this->uniqueKeyIndex->findMapKey($uniqueFp);
        if ($mapKey === null) {
            return null;
        }

        $entry = $this->entries[$mapKey] ?? null;
        if ($entry === null) {
            $this->uniqueKeyIndex->evict($uniqueFp);

            return null;
        }

        foreach ($equalityValues as $column => $value) {
            $fact = $entry->attributes->get($column);
            if (! $fact instanceof AttributeFact) {
                return null;
            }
            // phpcs:ignore SlevomatCodingStandard.Operators.DisallowEqualOperators
            if ($fact->originalValue != $value) {
                $this->uniqueKeyIndex->evict($uniqueFp);

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
        $uniqueFp = $this->uniqueKeyIndex->makeFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        return $this->uniqueKeyIndex->isAbsent($uniqueFp);
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
        $uniqueFp = $this->uniqueKeyIndex->makeFingerprint($connection, $modelClass, $table, $fingerprint, $equalityValues);

        $this->uniqueKeyIndex->recordAbsent($uniqueFp);
    }

    /**
     * Apply a mass-update to mapped entries for $modelClass without going to SQL.
     *
     * Entries whose predicate evaluates to Match have $values applied to their
     * attribute facts and underlying model object.  Unknown entries are evicted
     * (we cannot guarantee their post-update state).  Rejected entries are left
     * untouched.
     *
     * @param  array<string, mixed>  $values
     */
    public function applyMassUpdate(
        string $modelClass,
        PredicateNode $predicate,
        array $values,
        PredicateEvaluator $evaluator,
    ): bool {
        if ($this->disabled) {
            return false;
        }

        $this->ensureDiscoveredFor($modelClass);

        $keysToEvict = [];

        foreach ($this->entries as $mapKey => $entry) {
            if ($entry->modelClass !== $modelClass) {
                continue;
            }
            if ($entry->state !== LifecycleState::Exists) {
                continue;
            }
            $evalResult = $evaluator->evaluate($entry->attributes, $predicate);

            if ($evalResult === EvaluationResult::Match) {
                $rawAttrs = $entry->model->getAttributes();

                foreach ($values as $col => $val) {
                    $rawAttrs[$col] = $val;

                    $existing = $entry->attributes->get($col);

                    if ($existing instanceof AttributeFact) {
                        $existing->originalValue = $val;
                        $existing->currentValue = $val;
                        $existing->isDirty = false;
                        $existing->confidence = FactConfidence::Certain;
                        $existing->source = FactSource::MassWrite;
                    } else {
                        $entry->attributes->set($col, new AttributeFact(
                            column: $col,
                            originalValue: $val,
                            currentValue: $val,
                            isDirty: false,
                            confidence: FactConfidence::Certain,
                            source: FactSource::MassWrite,
                        ));
                    }
                }

                $entry->model->setRawAttributes($rawAttrs, true);
                $entry->version++;
                $this->uniqueKeyIndex->index($entry, $mapKey);
            } elseif ($evalResult === EvaluationResult::Unknown) {
                $keysToEvict[] = $mapKey;
            }
        }

        foreach ($keysToEvict as $key) {
            unset($this->entries[$key]);
        }

        return $keysToEvict !== [];
    }

    /**
     * Apply a mass-delete to mapped entries for $modelClass without going to SQL.
     *
     * Entries that Match the predicate are marked Deleted (or SoftDeleted with
     * absence tracking for soft-delete models).  Unknown entries are evicted.
     * Rejected entries are left untouched.
     */
    public function applyMassDelete(
        string $modelClass,
        PredicateNode $predicate,
        PredicateEvaluator $evaluator,
        bool $softDeletes,
    ): bool {
        if ($this->disabled) {
            return false;
        }

        $keysToEvict = [];

        foreach ($this->entries as $mapKey => $entry) {
            if ($entry->modelClass !== $modelClass) {
                continue;
            }
            if ($entry->state !== LifecycleState::Exists) {
                continue;
            }
            $evalResult = $evaluator->evaluate($entry->attributes, $predicate);

            if ($evalResult === EvaluationResult::Match) {
                if ($softDeletes) {
                    $entry->state = LifecycleState::SoftDeleted;
                    $this->absent[$mapKey] = true;
                } else {
                    $entry->state = LifecycleState::Deleted;
                }

                $entry->version++;
            } elseif ($evalResult === EvaluationResult::Unknown) {
                $keysToEvict[] = $mapKey;
            }
        }

        foreach ($keysToEvict as $key) {
            unset($this->entries[$key]);
        }

        return $keysToEvict !== [];
    }

    /** @return array<string, mixed> */
    public function debugStats(): array
    {
        return array_merge([
            'entries' => count($this->entries),
            'absent' => count($this->absent),
            'disabled' => $this->disabled,
        ], $this->uniqueKeyIndex->debugStats());
    }

    /** @return list<list<string>> */
    public function uniqueIndexesForModelClass(string $modelClass): array
    {
        $this->ensureDiscoveredFor($modelClass);

        return $this->uniqueKeyIndex->uniqueIndexesForModelClass($modelClass);
    }

    private function ensureDiscoveredFor(string $modelClass): void
    {
        if ($this->uniqueKeyIndex->hasDiscovered($modelClass)) {
            return;
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            $this->uniqueKeyIndex->markDiscovered($modelClass);

            return;
        }

        foreach (resolve(SchemaDiscovery::class)->uniqueIndexesFor($modelClass) as $columns) {
            $this->uniqueKeyIndex->register($modelClass, $columns);
        }

        $this->uniqueKeyIndex->markDiscovered($modelClass);
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
}
