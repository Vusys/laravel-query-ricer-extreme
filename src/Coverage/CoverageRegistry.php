<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Coverage;

use Vusys\QueryRicerExtreme\Predicate\PredicateColumns;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class CoverageRegistry
{
    /** @var list<CoverageEntry> */
    private array $entries = [];

    public function record(CoverageEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function findCovering(
        string $modelClass,
        string $connection,
        string $table,
        string $scopeFingerprint,
        PredicateNode $queryRegion,
    ): ?CoverageEntry {
        $checker = new SubsetChecker;

        foreach ($this->entries as $entry) {
            if ($entry->modelClass !== $modelClass) {
                continue;
            }

            if ($entry->connection !== $connection) {
                continue;
            }

            if ($entry->table !== $table) {
                continue;
            }

            if ($entry->scopeFingerprint !== $scopeFingerprint) {
                continue;
            }

            if (! $entry->complete) {
                continue;
            }

            if ($checker->isSubset($queryRegion, $entry->region)) {
                return $entry;
            }
        }

        return null;
    }

    public function flushModelClass(string $modelClass): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            static fn (CoverageEntry $e): bool => $e->modelClass !== $modelClass,
        ));
    }

    /**
     * Flush only coverage entries for $modelClass whose region predicate references
     * at least one of the given columns. Entries whose regions are disjoint from the
     * changed columns are preserved.
     *
     * @param  list<string>  $changedColumns
     */
    public function flushByColumns(string $modelClass, array $changedColumns): void
    {
        if ($changedColumns === []) {
            return;
        }

        $this->entries = array_values(array_filter(
            $this->entries,
            static function (CoverageEntry $e) use ($modelClass, $changedColumns): bool {
                if ($e->modelClass !== $modelClass) {
                    return true;
                }

                $regionColumns = PredicateColumns::fromNode($e->region);

                foreach ($changedColumns as $col) {
                    if (in_array($col, $regionColumns, true)) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }
}
