<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Coverage\CoverageEntry;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Predicate\AndNode;

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
    ): CoverageEntry {
        return new CoverageEntry(
            modelClass: $modelClass,
            connection: $connection,
            table: $table,
            scopeFingerprint: $scopeFingerprint,
            region: new AndNode([]),
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
}
