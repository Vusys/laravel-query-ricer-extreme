<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void flush(?string $modelClass = null)
 * @method static void forget(Model $model)
 * @method static mixed disabled(Closure $callback)
 * @method static list<Explanation> explain(Closure $callback)
 * @method static array<string, mixed> debugStats()
 */
class IdentityMap extends Facade
{
    #[\Override]
    protected static function getFacadeAccessor(): string
    {
        return IdentityMapStore::class;
    }
}
