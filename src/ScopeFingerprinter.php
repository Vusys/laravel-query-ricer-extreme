<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ScopeFingerprinter
{
    /**
     * @param  Builder<Model>  $builder
     */
    public static function fromBuilder(Builder $builder): string
    {
        $parts = self::softDeletePart($builder);

        return $parts === [] ? 'default' : implode(',', $parts);
    }

    public static function fromModel(Model $model): string
    {
        if (! self::usesSoftDeletes($model)) {
            return 'default';
        }

        $deletedAtColumn = method_exists($model, 'getDeletedAtColumn')
            ? $model->getDeletedAtColumn()
            : 'deleted_at';

        return $model->getAttribute($deletedAtColumn) !== null
            ? 'soft-delete:with-trashed'
            : 'soft-delete:default';
    }

    /**
     * @param  Builder<Model>  $builder
     * @return list<string>
     */
    private static function softDeletePart(Builder $builder): array
    {
        if (! self::usesSoftDeletes($builder->getModel())) {
            return [];
        }

        $removedScopes = $builder->removedScopes();

        if (in_array(SoftDeletingScope::class, $removedScopes, true)) {
            return ['soft-delete:with-trashed'];
        }

        return ['soft-delete:default'];
    }

    private static function usesSoftDeletes(Model $model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }
}
