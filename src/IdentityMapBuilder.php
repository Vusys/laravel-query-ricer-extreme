<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Vusys\QueryRicerExtreme\Enums\LifecycleState;
use Vusys\QueryRicerExtreme\Enums\PlanType;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class IdentityMapBuilder extends Builder
{
    private bool $identityMapDisabled = false;

    public function withoutIdentityMap(): static
    {
        $clone = clone $this;
        $clone->identityMapDisabled = true;

        return $clone;
    }

    /**
     * @param  mixed  $id
     * @param  list<string>  $columns
     * @return TModel|Collection<int, TModel>|null
     */
    #[\Override]
    public function find($id, $columns = ['*']): mixed
    {
        if (is_array($id) || $id instanceof Arrayable) {
            /** @var Collection<int, TModel> */
            return $this->whereKey($id)->get($columns);
        }

        if ($this->identityMapDisabled) {
            return $this->whereKey($id)->first($columns);
        }

        if (! is_int($id) && ! is_string($id)) {
            return $this->whereKey($id)->first($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return $this->whereKey($id)->first($columns);
        }

        $connection = $this->getModel()->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);
        $entry = $store->find(
            connection: $connection,
            modelClass: $model::class,
            table: $model->getTable(),
            primaryKeyName: $model->getKeyName(),
            primaryKeyValue: $id,
            fingerprint: $fingerprint,
        );

        if ($entry !== null && $entry->state === LifecycleState::Exists && $entry->attributes->satisfies($columns)) {
            $store->capture(new Explanation(
                type: PlanType::ReturnModelFromMemory,
                modelClass: $model::class,
                reason: 'exact-primary-key-hit',
                sqlExecuted: false,
                memoryKeys: [$id],
            ));
            /** @var TModel $cached */
            $cached = $entry->model;

            return $cached;
        }

        if ($store->isAbsent(
            connection: $connection,
            modelClass: $model::class,
            table: $model->getTable(),
            primaryKeyName: $model->getKeyName(),
            primaryKeyValue: $id,
            fingerprint: $fingerprint,
        )) {
            $store->capture(new Explanation(
                type: PlanType::ReturnNull,
                modelClass: $model::class,
                reason: 'primary-key-absence-tracked',
                sqlExecuted: false,
            ));

            return null;
        }

        $store->capture(new Explanation(
            type: PlanType::ExecuteNormally,
            modelClass: $model::class,
            reason: 'no-map-entry',
            sqlExecuted: true,
        ));

        $result = $this->whereKey($id)->first($columns);

        if ($result instanceof Model) {
            if ($columns === ['*']) {
                $store->markAllColumnsKnown($result);
            }
        } elseif ($result === null) {
            $store->recordAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $id,
                fingerprint: $fingerprint,
            );
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     * @return array<int, TModel>
     */
    #[\Override]
    public function getModels($columns = ['*']): array
    {
        if ($this->identityMapDisabled) {
            return parent::getModels($columns);
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $store = resolve(IdentityMapStore::class);

        if ($store->isDisabled()) {
            return parent::getModels($columns);
        }

        $connection = $this->getModel()->getConnection()->getName() ?? 'default';
        $fingerprint = ScopeFingerprinter::fromBuilder($this);

        // --- single primary-key lookup ---
        $primaryKeyId = $this->extractSinglePrimaryKeyLookup();

        if ($primaryKeyId !== null) {
            $entry = $store->find(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            );

            if ($entry !== null && $entry->state === LifecycleState::Exists && $entry->attributes->satisfies($columns)) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnCollectionFromMemory,
                    modelClass: $model::class,
                    reason: 'exact-primary-key-hit-via-where',
                    sqlExecuted: false,
                    memoryKeys: [$primaryKeyId],
                ));

                /** @var TModel $cached */
                $cached = $entry->model;

                return [$cached];
            }

            if ($store->isAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            )) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnEmptyCollection,
                    modelClass: $model::class,
                    reason: 'primary-key-absence-tracked',
                    sqlExecuted: false,
                ));

                return [];
            }
        }

        // --- bounded primary key-set lookup ---
        $keySetExtracted = $this->extractPrimaryKeySet();

        if ($keySetExtracted !== null) {
            $keySet = $keySetExtracted;

            [$hits, , $unknownKeys] = $store->partitionKeySet(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                keys: $keySet,
                fingerprint: $fingerprint,
                columns: $columns,
            );

            $hitModels = [];
            $hitKeys = [];
            foreach ($hits as $hitKey => $hitEntry) {
                /** @var TModel $hitModel */
                $hitModel = $hitEntry->model;
                $hitModels[] = $hitModel;
                $hitKeys[] = $hitKey;
            }

            if ($unknownKeys === []) {
                $store->capture(new Explanation(
                    type: PlanType::ReturnCollectionFromMemory,
                    modelClass: $model::class,
                    reason: 'all-keys-known-or-absent',
                    sqlExecuted: false,
                    memoryKeys: $hitKeys,
                ));

                return $this->mergeByInputOrder($hitModels, [], $keySet);
            }

            // Rewrite the query: add a second whereKey constraint for only the unknown keys.
            // The intersection of the original IN clause and this new one resolves to unknownKeys only.
            $rewriteBuilder = $this->withoutIdentityMap();
            $rewriteBuilder->whereKey($unknownKeys);

            /** @var array<int, TModel> $fetched */
            $fetched = $rewriteBuilder->getModels($columns);

            $isFullSelect = $columns === ['*'];

            $fetchedByKey = [];
            foreach ($fetched as $fetchedModel) {
                if ($isFullSelect) {
                    $store->markAllColumnsKnown($fetchedModel);
                }
                $k = $fetchedModel->getKey();
                if (is_int($k) || is_string($k)) {
                    $fetchedByKey[$k] = true;
                }
            }

            foreach ($unknownKeys as $unknownKey) {
                if (! isset($fetchedByKey[$unknownKey])) {
                    $store->recordAbsent(
                        connection: $connection,
                        modelClass: $model::class,
                        table: $model->getTable(),
                        primaryKeyName: $model->getKeyName(),
                        primaryKeyValue: $unknownKey,
                        fingerprint: $fingerprint,
                    );
                }
            }

            $store->capture(new Explanation(
                type: PlanType::RewritePrimaryKeysAndMerge,
                modelClass: $model::class,
                reason: 'key-set-rewrite',
                sqlExecuted: true,
                knownKeys: $hitKeys,
                missingKeys: $unknownKeys,
                memoryKeys: $hitKeys,
            ));

            return $this->mergeByInputOrder($hitModels, $fetched, $keySet);
        }

        // --- fallthrough: execute SQL normally ---
        $models = parent::getModels($columns);

        $isFullSelect = $columns === ['*'];

        foreach ($models as $result) {
            if ($isFullSelect) {
                $store->markAllColumnsKnown($result);
            }
        }

        if ($primaryKeyId !== null && $models === []) {
            $store->recordAbsent(
                connection: $connection,
                modelClass: $model::class,
                table: $model->getTable(),
                primaryKeyName: $model->getKeyName(),
                primaryKeyValue: $primaryKeyId,
                fingerprint: $fingerprint,
            );
        }

        return $models;
    }

    /**
     * @return list<int|string>|null
     */
    private function extractPrimaryKeySet(): ?array
    {
        $query = $this->getQuery();

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $qualifiedKey = $model->getQualifiedKeyName();
        $unqualifiedKey = $model->getKeyName();

        $inWhere = null;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                in_array($type, ['In', 'InRaw'], true)
                && is_string($column)
                && in_array($column, [$qualifiedKey, $unqualifiedKey], true)
                && $boolean === 'and'
            ) {
                if ($inWhere !== null) {
                    return null;
                }
                $inWhere = $where;

                continue;
            }

            if (! $this->isSafeGlobalScopeWhere($where)) {
                return null;
            }
        }

        if ($inWhere === null) {
            return null;
        }

        $values = $inWhere['values'] ?? null;

        if (! is_array($values) || $values === []) {
            return null;
        }

        $keys = [];

        foreach ($values as $value) {
            if (! is_int($value) && ! is_string($value)) {
                return null;
            }

            $keys[] = $value;
        }

        return $keys;
    }

    /**
     * @param  array<int, TModel>  $memoryModels
     * @param  array<int, TModel>  $fetchedModels
     * @param  list<int|string>  $keyOrder
     * @return array<int, TModel>
     */
    private function mergeByInputOrder(array $memoryModels, array $fetchedModels, array $keyOrder): array
    {
        /** @var array<int|string, TModel> $byKey */
        $byKey = [];

        foreach ($memoryModels as $m) {
            $k = $m->getKey();
            if (is_int($k) || is_string($k)) {
                $byKey[$k] = $m;
            }
        }

        foreach ($fetchedModels as $m) {
            $k = $m->getKey();
            if (is_int($k) || is_string($k)) {
                $byKey[$k] = $m;
            }
        }

        $result = [];

        foreach ($keyOrder as $key) {
            if (isset($byKey[$key])) {
                $result[] = $byKey[$key];
            }
        }

        return $result;
    }

    private function extractSinglePrimaryKeyLookup(): int|string|null
    {
        $query = $this->getQuery();

        /** @var array<int, array<string, mixed>> $wheres */
        $wheres = $query->wheres;

        if ($wheres === []) {
            return null;
        }

        /** @var TModel $model */
        $model = $this->getModel();
        $qualifiedKey = $model->getQualifiedKeyName();
        $unqualifiedKey = $model->getKeyName();

        $pkWhere = null;

        foreach ($wheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $operator = $where['operator'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                $type === 'Basic'
                && is_string($column)
                && in_array($column, [$qualifiedKey, $unqualifiedKey], true)
                && $operator === '='
                && $boolean === 'and'
            ) {
                if ($pkWhere !== null) {
                    return null;
                }

                $pkWhere = $where;

                continue;
            }

            if (! $this->isSafeGlobalScopeWhere($where)) {
                return null;
            }
        }

        if ($pkWhere === null) {
            return null;
        }

        $value = $pkWhere['value'] ?? null;

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        return $value;
    }

    /** @param array<string, mixed> $where */
    private function isSafeGlobalScopeWhere(array $where): bool
    {
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;

        return $type === 'Null' && is_string($column) && str_ends_with($column, 'deleted_at');
    }
}
