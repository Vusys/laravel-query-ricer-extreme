<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;

final class ScopeFingerprinter
{
    /**
     * Cache for extraScopePart output keyed by `class|removed-scopes`. Global
     * scopes are class-level static state in Eloquent — they're registered in
     * the model's `boot()` and don't change at runtime under normal use, so
     * the canonical output is deterministic per (class, removed-scopes) pair.
     * The profile shows this function dominating find() time because it
     * applyScopes()'s a fresh builder, walks the WHERE clauses, and (via
     * SoftDeletes) calls Model::getTable() → Str::pluralStudly() per call.
     *
     * @var array<string, string>
     */
    private static array $extraScopeCache = [];

    /** @var array<class-string<Model>, bool> */
    private static array $usesSoftDeletesCache = [];

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $builder
     */
    public static function fromBuilder(Builder $builder): string
    {
        $parts = self::softDeletePart($builder);

        $extra = self::extraScopePart($builder);
        if ($extra !== '') {
            $parts[] = 'scope:'.$extra;
        }

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
     * Clear the per-class memoization. Tests that register global scopes
     * mid-suite, or production code that mutates scopes at runtime, must
     * call this to avoid serving stale fingerprints.
     */
    public static function flush(): void
    {
        self::$extraScopeCache = [];
        self::$usesSoftDeletesCache = [];
    }

    /**
     * @template T of Model
     *
     * @param  Builder<T>  $builder
     * @return list<string>
     */
    private static function softDeletePart(Builder $builder): array
    {
        if (! self::usesSoftDeletes($builder->getModel())) {
            return [];
        }

        $removedScopes = $builder->removedScopes();

        if (! in_array(SoftDeletingScope::class, $removedScopes, true)) {
            return ['soft-delete:default'];
        }

        $model = $builder->getModel();
        $deletedAt = method_exists($model, 'getDeletedAtColumn')
            ? $model->getDeletedAtColumn()
            : 'deleted_at';
        $qualifiedDeletedAt = method_exists($model, 'getQualifiedDeletedAtColumn')
            ? $model->getQualifiedDeletedAtColumn()
            : $model->getTable().'.'.$deletedAt;

        foreach ($builder->getQuery()->wheres as $where) {
            if (
                ($where['type'] ?? null) === 'NotNull'
                && ($where['boolean'] ?? null) === 'and'
                && is_string($where['column'] ?? null)
                && in_array($where['column'], [$deletedAt, $qualifiedDeletedAt], true)
            ) {
                return ['soft-delete:only-trashed'];
            }
        }

        return ['soft-delete:with-trashed'];
    }

    /**
     * Hash extra global-scope WHERE clauses beyond the soft-delete guard.
     *
     * Mirrors the removed-scope list from $builder onto a fresh query so that
     * withoutGlobalScope(...) calls are respected: scopes the caller explicitly
     * removed are excluded from the fingerprint hash.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $builder
     */
    private static function extraScopePart(Builder $builder): string
    {
        $model = $builder->getModel();
        $removed = $builder->removedScopes();
        $cacheKey = $model::class.'|'.implode(',', $removed);

        if (isset(self::$extraScopeCache[$cacheKey])) {
            return self::$extraScopeCache[$cacheKey];
        }

        $deletedAt = method_exists($model, 'getDeletedAtColumn')
            ? $model->getDeletedAtColumn()
            : 'deleted_at';
        $qualifiedDeletedAt = method_exists($model, 'getQualifiedDeletedAtColumn')
            ? $model->getQualifiedDeletedAtColumn()
            : $model->getTable().'.'.$deletedAt;

        $freshBuilder = $model->newQuery();
        foreach ($removed as $removedScope) {
            $freshBuilder->withoutGlobalScope($removedScope);
        }

        /** @var array<int, array<string, mixed>> $scopeWheres */
        $scopeWheres = $freshBuilder->applyScopes()->getQuery()->wheres;

        $clauses = [];

        foreach ($scopeWheres as $where) {
            $type = $where['type'] ?? null;
            $column = $where['column'] ?? null;
            $boolean = $where['boolean'] ?? null;

            if (
                $type === 'Null'
                && $boolean === 'and'
                && is_string($column)
                && in_array($column, [$deletedAt, $qualifiedDeletedAt], true)
            ) {
                continue;
            }

            $encoded = json_encode($where, JSON_PARTIAL_OUTPUT_ON_ERROR);

            if (! is_string($encoded)) {
                $type = is_string($where['type']) ? $where['type'] : '';
                $col = is_string($where['column']) ? $where['column'] : '';
                $clauses[] = $type.'|'.$col;
            } else {
                $clauses[] = $encoded;
            }
        }

        if ($clauses === []) {
            return self::$extraScopeCache[$cacheKey] = '';
        }

        sort($clauses);

        return self::$extraScopeCache[$cacheKey] = md5(implode('|', $clauses));
    }

    private static function usesSoftDeletes(Model $model): bool
    {
        $class = $model::class;

        return self::$usesSoftDeletesCache[$class] ??= in_array(
            SoftDeletes::class,
            class_uses_recursive($model),
            true,
        );
    }
}
