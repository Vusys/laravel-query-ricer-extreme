<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\QueryRicerExtreme\Query\IdentityMapBuilder;
use Vusys\QueryRicerExtreme\Relations\MemoryBelongsTo;
use Vusys\QueryRicerExtreme\Relations\MemoryHasMany;
use Vusys\QueryRicerExtreme\Relations\MemoryMorphMany;
use Vusys\QueryRicerExtreme\Relations\MemoryMorphTo;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

trait HasIdentityMap
{
    /**
     * @return IdentityMapBuilder<Model>
     */
    public function newEloquentBuilder($query): IdentityMapBuilder
    {
        return new IdentityMapBuilder($query);
    }

    protected function newBelongsTo(Builder $query, Model $child, $foreignKey, $ownerKey, $relation): BelongsTo
    {
        return new MemoryBelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    protected function newMorphTo(Builder $query, Model $parent, $foreignKey, $ownerKey, $type, $relation): MorphTo
    {
        return new MemoryMorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    protected function newHasMany(Builder $query, Model $parent, $foreignKey, $localKey): HasMany
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $callerFrame = $frames[2] ?? [];
        $name = is_string($callerFrame['function'] ?? null) ? $callerFrame['function'] : null;

        return (new MemoryHasMany($query, $parent, $foreignKey, $localKey))->withRelationName($name);
    }

    protected function newMorphMany(Builder $query, Model $parent, $type, $id, $localKey): MorphMany
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $callerFrame = $frames[2] ?? [];
        $name = is_string($callerFrame['function'] ?? null) ? $callerFrame['function'] : null;

        return (new MemoryMorphMany($query, $parent, $type, $id, $localKey))->withRelationName($name);
    }

    protected static function bootHasIdentityMap(): void
    {
        static::retrieved(function (Model $model): void {
            resolve(IdentityMapStore::class)->remember($model);
        });

        static::saved(function (Model $model): void {
            resolve(IdentityMapStore::class)->afterSaved($model);
        });

        static::deleted(function (Model $model): void {
            resolve(IdentityMapStore::class)->afterDeleted($model);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restored', function (Model $model): void {
                resolve(IdentityMapStore::class)->afterSaved($model);
            });

            static::registerModelEvent('forceDeleted', function (Model $model): void {
                resolve(IdentityMapStore::class)->afterForceDeleted($model);
            });
        }
    }
}
