<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

trait HasIdentityMap
{
    /**
     * @return IdentityMapBuilder<Model>
     */
    public function newEloquentBuilder($query): IdentityMapBuilder
    {
        return new IdentityMapBuilder($query);
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

        static::restored(function (Model $model): void {
            resolve(IdentityMapStore::class)->afterSaved($model);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleted(function (Model $model): void {
                resolve(IdentityMapStore::class)->afterForceDeleted($model);
            });
        }
    }
}
