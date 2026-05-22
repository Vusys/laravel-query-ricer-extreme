<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Enums;

enum EvaluationResult
{
    case Match;
    case Reject;
    case Unknown;
}
