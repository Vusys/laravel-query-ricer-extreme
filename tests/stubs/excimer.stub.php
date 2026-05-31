<?php

declare(strict_types=1);

const EXCIMER_REAL = 0;

const EXCIMER_CPU = 1;

class ExcimerProfiler
{
    public function setPeriod(float $period): void {}

    public function setEventType(int $type): void {}

    public function start(): void {}

    public function stop(): void {}

    public function getLog(): ExcimerLog
    {
        return new ExcimerLog;
    }
}

/** @implements IteratorAggregate<int, mixed> */
class ExcimerLog implements Countable, IteratorAggregate
{
    public function count(): int
    {
        return 0;
    }

    public function getIterator(): Iterator
    {
        return new ArrayIterator([]);
    }

    public function formatCollapsed(): string
    {
        return '';
    }

    /** @return array<string, array<string, int>> */
    public function aggregateByFunction(): array
    {
        return [];
    }
}
