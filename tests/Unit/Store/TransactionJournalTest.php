<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Store\JournalEntry;
use Vusys\QueryRicerExtreme\Store\TransactionJournal;

final class TransactionJournalTest extends TestCase
{
    private const string CONN = 'default';

    #[Test]
    public function is_inactive_before_begin(): void
    {
        $journal = new TransactionJournal;

        $this->assertFalse($journal->isActive(self::CONN));
        $this->assertSame(0, $journal->depth(self::CONN));
    }

    #[Test]
    public function begin_then_rollback_returns_snapshots_for_that_level(): void
    {
        $journal = new TransactionJournal;

        $journal->begin(self::CONN);
        $this->assertTrue($journal->isActive(self::CONN));
        $this->assertSame(1, $journal->depth(self::CONN));

        $entry = $this->makeEntry('default|App\\User|users|id|1|fp');
        $journal->snapshot(self::CONN, $entry);

        $restored = $journal->rollback(self::CONN);

        $this->assertCount(1, $restored);
        $this->assertSame($entry, $restored[0]);
        $this->assertFalse($journal->isActive(self::CONN));
    }

    #[Test]
    public function snapshot_is_idempotent_per_key_at_each_level(): void
    {
        $journal = new TransactionJournal;
        $journal->begin(self::CONN);

        $key = 'default|App\\User|users|id|1|fp';
        $first = $this->makeEntry($key);
        $second = $this->makeEntry($key);

        $journal->snapshot(self::CONN, $first);
        $journal->snapshot(self::CONN, $second);

        $restored = $journal->rollback(self::CONN);

        $this->assertCount(1, $restored);
        $this->assertSame($first, $restored[0], 'second snapshot for same key must be ignored');
    }

    #[Test]
    public function snapshot_outside_any_transaction_is_a_noop(): void
    {
        $journal = new TransactionJournal;

        $journal->snapshot(self::CONN, $this->makeEntry('default|App\\User|users|id|1|fp'));

        $this->assertFalse($journal->isActive(self::CONN));
        $this->assertSame([], $journal->rollback(self::CONN));
    }

    #[Test]
    public function commit_at_outermost_level_discards_journal_entries(): void
    {
        $journal = new TransactionJournal;
        $journal->begin(self::CONN);
        $journal->snapshot(self::CONN, $this->makeEntry('default|App\\User|users|id|1|fp'));

        $journal->commit(self::CONN);

        $this->assertFalse($journal->isActive(self::CONN));
        $this->assertSame([], $journal->rollback(self::CONN));
    }

    #[Test]
    public function rollback_at_inner_level_returns_inner_snapshots_only(): void
    {
        $journal = new TransactionJournal;

        $journal->begin(self::CONN);
        $outer = $this->makeEntry('default|App\\User|users|id|outer|fp');
        $journal->snapshot(self::CONN, $outer);

        $journal->begin(self::CONN);
        $inner = $this->makeEntry('default|App\\User|users|id|inner|fp');
        $journal->snapshot(self::CONN, $inner);

        $restored = $journal->rollback(self::CONN);

        $this->assertCount(1, $restored);
        $this->assertSame($inner, $restored[0]);
        $this->assertTrue($journal->isActive(self::CONN), 'outer transaction must still be open');
        $this->assertSame(1, $journal->depth(self::CONN));
    }

    #[Test]
    public function commit_inner_promotes_snapshots_to_parent_level(): void
    {
        $journal = new TransactionJournal;

        $journal->begin(self::CONN);
        $outer = $this->makeEntry('default|App\\User|users|id|outer|fp');
        $journal->snapshot(self::CONN, $outer);

        $journal->begin(self::CONN);
        $inner = $this->makeEntry('default|App\\User|users|id|inner|fp');
        $journal->snapshot(self::CONN, $inner);

        $journal->commit(self::CONN);

        $this->assertSame(1, $journal->depth(self::CONN));

        $restored = $journal->rollback(self::CONN);

        $keys = array_map(static fn (JournalEntry $e): string => $e->entryKey, $restored);

        $this->assertContains('default|App\\User|users|id|outer|fp', $keys);
        $this->assertContains('default|App\\User|users|id|inner|fp', $keys);
        $this->assertCount(2, $restored);
    }

    #[Test]
    public function commit_inner_does_not_overwrite_existing_parent_snapshot_for_same_key(): void
    {
        $journal = new TransactionJournal;

        $key = 'default|App\\User|users|id|same|fp';

        $journal->begin(self::CONN);
        $outerEntry = $this->makeEntry($key);
        $journal->snapshot(self::CONN, $outerEntry);

        $journal->begin(self::CONN);
        $innerEntry = $this->makeEntry($key);
        $journal->snapshot(self::CONN, $innerEntry);

        $journal->commit(self::CONN);

        $restored = $journal->rollback(self::CONN);

        $this->assertCount(1, $restored);
        $this->assertSame($outerEntry, $restored[0], 'parent snapshot wins on commit-merge');
    }

    #[Test]
    public function flush_clears_all_levels_across_all_connections(): void
    {
        $journal = new TransactionJournal;
        $journal->begin('one');
        $journal->begin('one');
        $journal->begin('two');
        $journal->snapshot('one', $this->makeEntry('one|App\\User|users|id|1|fp'));

        $journal->flush();

        $this->assertFalse($journal->isActive('one'));
        $this->assertFalse($journal->isActive('two'));
        $this->assertSame(0, $journal->depth('one'));
        $this->assertSame(0, $journal->depth('two'));
    }

    #[Test]
    public function commit_outside_any_transaction_is_a_noop(): void
    {
        $journal = new TransactionJournal;

        $journal->commit(self::CONN);

        $this->assertFalse($journal->isActive(self::CONN));
    }

    #[Test]
    public function transactions_on_different_connections_do_not_interfere(): void
    {
        $journal = new TransactionJournal;

        $journal->begin('primary');
        $journal->begin('secondary');

        $primaryEntry = $this->makeEntry('primary|App\\User|users|id|1|fp');
        $secondaryEntry = $this->makeEntry('secondary|App\\Order|orders|id|9|fp');
        $journal->snapshot('primary', $primaryEntry);
        $journal->snapshot('secondary', $secondaryEntry);

        $primaryRestored = $journal->rollback('primary');
        $this->assertSame([$primaryEntry], $primaryRestored);
        $this->assertFalse($journal->isActive('primary'));
        $this->assertTrue($journal->isActive('secondary'), 'rolling back primary must not touch secondary');

        $secondaryRestored = $journal->rollback('secondary');
        $this->assertSame([$secondaryEntry], $secondaryRestored);
    }

    private function makeEntry(string $key): JournalEntry
    {
        $parts = explode('|', $key, 3);
        $modelClass = $parts[1] ?? '';

        return new JournalEntry(
            entryKey: $key,
            modelClass: $modelClass,
            before: null,
            wasAbsent: false,
            modelOriginal: null,
        );
    }
}
