<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Query;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-class memoization of Model::getTable() and the connection name.
 *
 * Both depend almost always only on the model class — `$table` and `$connection`
 * are class-level conventions in Eloquent. The profile after #47 showed
 * Model::getTable / Str::pluralStudly / Pluralizer::plural dominating the
 * hot path on every find / first / get because models that don't set
 * `protected $table` re-run the pluralization on every fresh instance, and
 * the package creates many fresh instances per request.
 *
 * Cache is keyed by `Model::class` so different models get different entries.
 * Models that explicitly mutate `$table` per instance won't see the cached
 * value — the cache is correct for the dominant pattern and a `flush()`
 * escape hatch handles the unusual case. flush() is wired into
 * IdentityMapStore::flush() so the existing $store->flush() test pattern
 * also resets this cache.
 */
final class ModelMetadata
{
    /** @var array<class-string<Model>, string> */
    private static array $tableCache = [];

    /** @var array<class-string<Model>, string> */
    private static array $connectionCache = [];

    public static function table(Model $model): string
    {
        return self::$tableCache[$model::class] ??= $model->getTable();
    }

    public static function connection(Model $model): string
    {
        return self::$connectionCache[$model::class] ??= $model->getConnection()->getName() ?? 'default';
    }

    public static function flush(): void
    {
        self::$tableCache = [];
        self::$connectionCache = [];
    }
}
