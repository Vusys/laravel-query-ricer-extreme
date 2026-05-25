<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Driver;

use Illuminate\Database\Eloquent\Model;

/**
 * Default resolver: returns ColumnSemantics::unknown() for every column.
 *
 * Used when no schema metadata is available. Driver profiles that require
 * known column semantics should return Unknown rather than guess.
 */
final class NullColumnSemanticsResolver implements ColumnSemanticsResolver
{
    #[\Override]
    public function for(Model $model, string $column): ColumnSemantics
    {
        return ColumnSemantics::unknown();
    }
}
