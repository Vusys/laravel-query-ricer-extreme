<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Models\Pivots;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Custom pivot whose casts make the in-memory attribute values diverge from the
 * raw column values SQL compares against in wherePivot: `active` becomes a real
 * bool and `created_at` becomes a Carbon instance.
 */
final class CastTagging extends Pivot
{
    public $incrementing = true;

    /** @var array<string, string> */
    protected $casts = [
        'active' => 'boolean',
        'priority' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
