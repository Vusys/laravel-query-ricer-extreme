<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Coverage;

final readonly class ColumnSet
{
    public bool $allColumns;

    /** @var list<string> */
    public array $columns;

    /** @param list<string> $columns */
    public function __construct(array $columns)
    {
        $this->allColumns = $columns === ['*'];
        $this->columns = $columns;
    }

    /** @param list<string> $requested */
    public function covers(array $requested): bool
    {
        if ($this->allColumns) {
            return true;
        }

        foreach ($requested as $col) {
            if ($col === '*') {
                return $this->allColumns;
            }

            if (! in_array($col, $this->columns, true)) {
                return false;
            }
        }

        return true;
    }
}
