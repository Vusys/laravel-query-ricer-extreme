<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Enums;

enum FactSource: string
{
    case HydratedFromDatabase = 'hydrated-from-database';
    case AssignedInMemory = 'assigned-in-memory';
    case CastedModelAttribute = 'casted-model-attribute';
    case AppendedAttribute = 'appended-attribute';
    case RelationDerived = 'relation-derived';
    case Unknown = 'unknown';
}
