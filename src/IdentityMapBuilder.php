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
            $store->markAllColumnsKnown($result);
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
