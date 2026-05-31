<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-class memoization of Model::getTable().
 *
 * `$table` is a class-level convention in Eloquent — Eloquent itself only
 * exposes it as an instance property because of how models are constructed,
 * but its value is deterministic for the model class. The profile after #47
 * showed Model::getTable / Str::pluralStudly / Pluralizer::plural dominating
 * the hot path because models that don't set `protected $table` re-run
 * pluralization on every fresh instance, and the package creates many
 * fresh instances per request.
 *
 * Cache is keyed by `Model::class`. flush() is wired into
 * IdentityMapStore::flush() so the existing $store->flush() test pattern
 * also resets this cache.
 *
 * Connection name is NOT cached: codebases using a per-instance
 * `$this->setConnection(...)` to swap connections at runtime (tenancy,
 * test contexts, sharding) would see the first-observed connection cached
 * and returned for every subsequent call, breaking correctness. We keep
 * the API surface so callers don't churn, but it's a pass-through.
 */
final class ModelMetadata
{
    /** @var array<class-string<Model>, string> */
    private static array $tableCache = [];

    public static function table(Model $model): string
    {
        return self::$tableCache[$model::class] ??= $model->getTable();
    }

    public static function connection(Model $model): string
    {
        return $model->getConnection()->getName() ?? 'default';
    }

    public static function flush(): void
    {
        self::$tableCache = [];
    }
}
