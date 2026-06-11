<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Graph\ModelIdentity;
use Vusys\QueryRicerExtreme\Query\IdentityMapBuilder;
use Vusys\QueryRicerExtreme\Relations\MemoryBelongsTo;
use Vusys\QueryRicerExtreme\Relations\MemoryBelongsToMany;
use Vusys\QueryRicerExtreme\Relations\MemoryHasMany;
use Vusys\QueryRicerExtreme\Relations\MemoryMorphMany;
use Vusys\QueryRicerExtreme\Relations\MemoryMorphTo;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;

trait HasIdentityMap
{
    /**
     * Per-class cache of computed table names. Each class using the trait
     * gets its own static (PHP trait semantics) so the cache is naturally
     * scoped — one entry per class, storing its own table name.
     *
     * @var array<class-string<Model>, string>
     */
    private static array $tableNameCache = [];

    /**
     * Per-relation-signature memo of the guessed relation name.
     *
     * The name is guessed once, via debug_backtrace, from the first
     * instantiation of a given relation and reused thereafter — eliminating the
     * backtrace cost on every subsequent `$model->relation` access. The key is
     * the relation's structural signature (declaring class, related class, and
     * keys), NOT the guessed method name: a complete relation fact is only ever
     * recorded for an unconstrained load, whose row set is fully determined by
     * that signature, so two methods sharing one signature are interchangeable
     * for memory purposes. `null` (un-guessable name) is memoised too, so the
     * fallback path is not re-walked on every call.
     *
     * @var array<string, string|null>
     */
    private static array $relationNameMemo = [];

    /**
     * Fires once per Model::__construct. Eloquent's Model::getTable() returns
     * `$this->table ?? Str::snake(Str::pluralStudly(class_basename($this)))`,
     * so the pluralization chain re-runs on every fresh instance unless the
     * class declares `protected $table = '...'`. We set $this->table eagerly
     * from a class-level cache here so Eloquent's existing short-circuit
     * returns the cached value immediately — and crucially this benefits
     * Eloquent's own internal getTable() calls (hydration, scope application,
     * relation key qualification) that the package's ModelMetadata helper
     * can't intercept.
     */
    public function initializeHasIdentityMap(): void
    {
        if ($this->table === null) {
            $this->table = self::$tableNameCache[static::class] ??= $this->getTable();
        }
    }

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
        $signature = static::class.'|hasMany|'.$query->getModel()::class.'|'.serialize([$foreignKey, $localKey]);
        $name = $this->memoisedRelationName($signature);

        return (new MemoryHasMany($query, $parent, $foreignKey, $localKey))->withRelationName($name);
    }

    protected function newMorphMany(Builder $query, Model $parent, $type, $id, $localKey): MorphMany
    {
        $signature = static::class.'|morphMany|'.$query->getModel()::class.'|'.serialize([$type, $id, $localKey]);
        $name = $this->memoisedRelationName($signature);

        return (new MemoryMorphMany($query, $parent, $type, $id, $localKey))->withRelationName($name);
    }

    /**
     * Guess the calling relation method's name from the backtrace, once per
     * structural signature. The frame depth is measured from this helper:
     * frame 0 = this method, 1 = newHasMany/newMorphMany, 2 = Eloquent's
     * hasMany/morphMany factory, 3 = the user's relation method.
     */
    private function memoisedRelationName(string $signature): ?string
    {
        if (array_key_exists($signature, self::$relationNameMemo)) {
            return self::$relationNameMemo[$signature];
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $callerFrame = $frames[3] ?? [];
        $name = is_string($callerFrame['function'] ?? null) ? $callerFrame['function'] : null;

        return self::$relationNameMemo[$signature] = $name;
    }

    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ): BelongsToMany {
        return new MemoryBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
        );
    }

    protected static function bootHasIdentityMap(): void
    {
        static::retrieved(function (Model $model): void {
            resolve(IdentityMapStore::class)->remember($model);
        });

        static::saved(function (Model $model): void {
            resolve(IdentityMapStore::class)->afterSaved($model);
            $graph = resolve(IdentityGraph::class);

            if ($model->wasRecentlyCreated) {
                resolve(CoverageRegistry::class)->flushModelClass($model::class);
                $graph->invalidateModelClass($model::class);
            } else {
                $changedColumns = array_keys($model->getChanges());

                if ($changedColumns !== []) {
                    resolve(CoverageRegistry::class)->flushByColumns($model::class, $changedColumns);
                }

                $identity = ModelIdentity::fromModel($model);
                if ($identity instanceof ModelIdentity) {
                    $graph->invalidateModel($identity);
                }
            }
        });

        static::deleted(function (Model $model): void {
            resolve(IdentityMapStore::class)->afterDeleted($model);
            resolve(CoverageRegistry::class)->flushModelClass($model::class);
            resolve(IdentityGraph::class)->invalidateModelClass($model::class);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('restored', function (Model $model): void {
                resolve(IdentityMapStore::class)->afterSaved($model);
                resolve(CoverageRegistry::class)->flushModelClass($model::class);
                resolve(IdentityGraph::class)->invalidateModelClass($model::class);
            });

            static::registerModelEvent('forceDeleted', function (Model $model): void {
                resolve(IdentityMapStore::class)->afterForceDeleted($model);
            });
        }
    }
}
