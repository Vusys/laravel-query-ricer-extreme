<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Enums;

enum RelationKind: string
{
    case BelongsTo = 'belongsTo';
    case HasOne = 'hasOne';
    case HasMany = 'hasMany';
    case BelongsToMany = 'belongsToMany';
    case MorphTo = 'morphTo';
    case MorphOne = 'morphOne';
    case MorphMany = 'morphMany';
}
