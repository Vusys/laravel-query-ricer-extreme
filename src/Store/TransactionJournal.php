<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Store;

final class TransactionJournal
{
    /** @var array<string, list<array<string, JournalEntry>>> connection → stack of levels; each level maps entry key → snapshot */
    private array $stacks = [];

    public function isActive(string $connection): bool
    {
        return ($this->stacks[$connection] ?? []) !== [];
    }

    public function begin(string $connection): void
    {
        $this->stacks[$connection][] = [];
    }

    /**
     * Record the before-state of a map entry before it is modified in the current transaction.
     * Only records the first snapshot per key at each nesting level (idempotent).
     */
    public function snapshot(string $connection, JournalEntry $entry): void
    {
        $stack = $this->stacks[$connection] ?? [];

        if ($stack === []) {
            return;
        }

        $level = count($stack) - 1;

        if (! isset($stack[$level][$entry->entryKey])) {
            $this->stacks[$connection][$level][$entry->entryKey] = $entry;
        }
    }

    /**
     * Commit the innermost transaction level on $connection: merge its journal into
     * the parent level (the parent now inherits responsibility for any further rollback).
     */
    public function commit(string $connection): void
    {
        $stack = $this->stacks[$connection] ?? [];

        if ($stack === []) {
            return;
        }

        $committed = array_pop($this->stacks[$connection]);

        if ($committed === null) {
            return;
        }

        if ($this->stacks[$connection] !== []) {
            $parentLevel = count($this->stacks[$connection]) - 1;

            foreach ($committed as $key => $entry) {
                $this->stacks[$connection][$parentLevel][$key] ??= $entry;
            }
        }
    }

    /**
     * Roll back the innermost level on $connection and return the snapshots to restore.
     *
     * @return list<JournalEntry>
     */
    public function rollback(string $connection): array
    {
        $stack = $this->stacks[$connection] ?? [];

        if ($stack === []) {
            return [];
        }

        $popped = array_pop($this->stacks[$connection]);

        return $popped === null ? [] : array_values($popped);
    }

    public function flush(): void
    {
        $this->stacks = [];
    }

    public function depth(string $connection): int
    {
        return count($this->stacks[$connection] ?? []);
    }
}
