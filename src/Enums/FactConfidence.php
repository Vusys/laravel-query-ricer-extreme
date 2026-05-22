<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Enums;

enum FactConfidence: string
{
    case Certain = 'certain';
    case Assumed = 'assumed';
}
