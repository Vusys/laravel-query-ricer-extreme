<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme;

use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class PredicateExtractor
{
    private const array SUPPORTED_OPERATORS = ['=', '!=', '<>'];

    /** @param array<string, mixed> $where */
    public static function fromWhere(array $where): ?PredicateNode
    {
        $type = $where['type'] ?? null;
        $column = $where['column'] ?? null;

        if (! is_string($column) || $column === '') {
            return null;
        }

        return match ($type) {
            'Basic' => self::fromBasicWhere($column, $where),
            'In' => self::fromInWhere($column, $where, false),
            'NotIn' => self::fromInWhere($column, $where, true),
            'Null' => new NullNode($column, false),
            'NotNull' => new NullNode($column, true),
            default => null,
        };
    }

    /** @param array<string, mixed> $where */
    private static function fromBasicWhere(string $column, array $where): ?PredicateNode
    {
        $operator = $where['operator'] ?? null;

        if (! is_string($operator) || ! in_array($operator, self::SUPPORTED_OPERATORS, true)) {
            return null;
        }

        return new ComparisonNode($column, $operator, $where['value'] ?? null);
    }

    /** @param array<string, mixed> $where */
    private static function fromInWhere(string $column, array $where, bool $negated): ?InNode
    {
        $values = $where['values'] ?? null;

        if (! is_array($values)) {
            return null;
        }

        foreach ($values as $v) {
            if ($v === null || ! is_scalar($v)) {
                return null;
            }
        }

        /** @var list<mixed> $values */
        return new InNode($column, $values, $negated);
    }
}
