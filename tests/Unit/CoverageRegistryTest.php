<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Coverage\CoverageEntry;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Predicate\ComparisonNode;
use Vusys\QueryRicerExtreme\Predicate\InNode;
use Vusys\QueryRicerExtreme\Predicate\NullNode;
use Vusys\QueryRicerExtreme\Predicate\PredicateNode;

final class CoverageRegistryTest extends TestCase
{
    private CoverageRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        $this->registry = new CoverageRegistry;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeEntry(
        string $modelClass = 'App\\User',
        string $connection = 'default',
        string $table = 'users',
        string $scopeFingerprint = 'fp',
        bool $complete = true,
        ?PredicateNode $region = null,
    ): CoverageEntry {
        return new CoverageEntry(
            modelClass: $modelClass,
            connection: $connection,
            table: $table,
            scopeFingerprint: $scopeFingerprint,
            region: $region ?? new AndNode([]),
            columns: new ColumnSet(['*']),
            primaryKeys: [1],
            complete: $complete,
            version: 1,
        );
    }

    // -------------------------------------------------------------------------
    // findCovering guard conditions
    // -------------------------------------------------------------------------

    #[Test]
    public function find_covering_skips_entry_with_different_model_class(): void
    {
        $this->registry->record($this->makeEntry(modelClass: 'App\\User'));

        $result = $this->registry->findCovering('App\\Post', 'default', 'users', 'fp', new AndNode([]));

        $this->assertNull($result);
    }

    #[Test]
    public function find_covering_skips_entry_with_different_connection(): void
    {
        $this->registry->record($this->makeEntry(connection: 'mysql'));

        $result = $this->registry->findCovering('App\\User', 'sqlite', 'users', 'fp', new AndNode([]));

        $this->assertNull($result);
    }

    #[Test]
    public function find_covering_skips_entry_with_different_table(): void
    {
        $this->registry->record($this->makeEntry(table: 'users'));

        $result = $this->registry->findCovering('App\\User', 'default', 'admins', 'fp', new AndNode([]));

        $this->assertNull($result);
    }

    #[Test]
    public function find_covering_skips_entry_with_different_scope_fingerprint(): void
    {
        $this->registry->record($this->makeEntry(scopeFingerprint: 'fp-a'));

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp-b', new AndNode([]));

        $this->assertNull($result);
    }

    #[Test]
    public function find_covering_skips_incomplete_entry(): void
    {
        $this->registry->record($this->makeEntry(complete: false));

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertNull($result);
    }

    #[Test]
    public function find_covering_returns_matching_entry(): void
    {
        $entry = $this->makeEntry();
        $this->registry->record($entry);

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertSame($entry, $result);
    }

    #[Test]
    public function find_covering_skips_mismatch_and_returns_later_match(): void
    {
        $this->registry->record($this->makeEntry(modelClass: 'App\\Post'));
        $matching = $this->makeEntry(modelClass: 'App\\User');
        $this->registry->record($matching);

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertSame($matching, $result);
    }

    #[Test]
    public function find_covering_skips_wrong_connection_and_returns_later_match(): void
    {
        // Entry 1: modelClass matches but connection does NOT — must continue, not break.
        $this->registry->record($this->makeEntry(connection: 'mysql'));
        $matching = $this->makeEntry(connection: 'default');
        $this->registry->record($matching);

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertSame($matching, $result);
    }

    #[Test]
    public function find_covering_skips_wrong_table_and_returns_later_match(): void
    {
        // Entry 1: modelClass + connection match but table does NOT.
        $this->registry->record($this->makeEntry(table: 'admins'));
        $matching = $this->makeEntry(table: 'users');
        $this->registry->record($matching);

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertSame($matching, $result);
    }

    #[Test]
    public function find_covering_skips_wrong_scope_fingerprint_and_returns_later_match(): void
    {
        // Entry 1: modelClass + connection + table match but scopeFingerprint does NOT.
        $this->registry->record($this->makeEntry(scopeFingerprint: 'fp-other'));
        $matching = $this->makeEntry(scopeFingerprint: 'fp');
        $this->registry->record($matching);

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertSame($matching, $result);
    }

    #[Test]
    public function find_covering_skips_incomplete_and_returns_later_complete_match(): void
    {
        // Entry 1: all fields match but complete = false — must continue, not break.
        $this->registry->record($this->makeEntry(complete: false));
        $matching = $this->makeEntry(complete: true);
        $this->registry->record($matching);

        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', new AndNode([]));

        $this->assertSame($matching, $result);
    }

    // -------------------------------------------------------------------------
    // SubsetChecker integration — non-trivial predicate regions
    // -------------------------------------------------------------------------

    #[Test]
    public function find_covering_rejects_entry_when_query_region_is_not_subset_of_recorded(): void
    {
        // Record: col = 1.  Query: col = 2.  Neither implies the other.
        $recorded = new ComparisonNode('col', '=', 1);
        $this->registry->record($this->makeEntry(region: $recorded));

        $query = new ComparisonNode('col', '=', 2);
        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', $query);

        $this->assertNull($result);
    }

    #[Test]
    public function find_covering_returns_entry_when_query_region_is_strict_subset_of_recorded(): void
    {
        // Record: col = 1 (a single equality constraint).
        // Query: AND(col = 1, other = 2) — a strict subset because it implies col = 1.
        $recorded = new ComparisonNode('col', '=', 1);
        $entry = $this->makeEntry(region: $recorded);
        $this->registry->record($entry);

        $query = new AndNode([
            new ComparisonNode('col', '=', 1),
            new ComparisonNode('other', '=', 2),
        ]);
        $result = $this->registry->findCovering('App\\User', 'default', 'users', 'fp', $query);

        $this->assertSame($entry, $result);
    }

    // -------------------------------------------------------------------------
    // flushByColumns
    // -------------------------------------------------------------------------

    #[Test]
    public function flush_by_columns_removes_entries_whose_region_references_changed_column(): void
    {
        $this->registry->record($this->makeEntry(region: new ComparisonNode('active', '=', true)));
        $this->registry->record($this->makeEntry(region: new ComparisonNode('name', '=', 'Alice')));
        $this->assertSame(2, $this->registry->entryCount());

        $this->registry->flushByColumns('App\\User', ['active']);

        $this->assertSame(1, $this->registry->entryCount());
    }

    #[Test]
    public function flush_by_columns_preserves_entries_with_unrelated_regions(): void
    {
        $this->registry->record($this->makeEntry(region: new ComparisonNode('name', '=', 'Alice')));
        $this->registry->record($this->makeEntry(region: new AndNode([])));
        $this->assertSame(2, $this->registry->entryCount());

        $this->registry->flushByColumns('App\\User', ['active']);

        $this->assertSame(2, $this->registry->entryCount());
    }

    #[Test]
    public function flush_by_columns_removes_and_node_containing_changed_column(): void
    {
        $region = new AndNode([
            new ComparisonNode('active', '=', true),
            new ComparisonNode('role', '=', 'admin'),
        ]);
        $this->registry->record($this->makeEntry(region: $region));
        $this->assertSame(1, $this->registry->entryCount());

        $this->registry->flushByColumns('App\\User', ['role']);

        $this->assertSame(0, $this->registry->entryCount());
    }

    #[Test]
    public function flush_by_columns_is_a_no_op_for_empty_column_list(): void
    {
        $this->registry->record($this->makeEntry(region: new ComparisonNode('active', '=', true)));
        $this->assertSame(1, $this->registry->entryCount());

        $this->registry->flushByColumns('App\\User', []);

        $this->assertSame(1, $this->registry->entryCount());
    }

    #[Test]
    public function flush_by_columns_does_not_touch_other_model_classes(): void
    {
        $this->registry->record($this->makeEntry(modelClass: 'App\\Post', region: new ComparisonNode('active', '=', true)));
        $this->registry->record($this->makeEntry(modelClass: 'App\\User', region: new ComparisonNode('active', '=', true)));
        $this->assertSame(2, $this->registry->entryCount());

        $this->registry->flushByColumns('App\\User', ['active']);

        $this->assertSame(1, $this->registry->entryCount());
    }

    #[Test]
    public function flush_by_columns_handles_in_node_and_null_node(): void
    {
        $this->registry->record($this->makeEntry(region: new InNode('status', ['a', 'b'], false)));
        $this->registry->record($this->makeEntry(region: new NullNode('deleted_at', false)));
        $this->assertSame(2, $this->registry->entryCount());

        $this->registry->flushByColumns('App\\User', ['status']);

        $this->assertSame(1, $this->registry->entryCount());
    }
}
