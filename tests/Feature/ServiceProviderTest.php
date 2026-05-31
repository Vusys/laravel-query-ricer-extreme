<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;
use Vusys\QueryRicerExtreme\Coverage\CoverageEntry;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Predicate\AndNode;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Store\TransactionJournal;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    #[Test]
    public function identity_map_store_is_bound_as_singleton(): void
    {
        $a = resolve(IdentityMapStore::class);
        $b = resolve(IdentityMapStore::class);

        $this->assertInstanceOf(IdentityMapStore::class, $a);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function coverage_registry_is_bound_as_singleton(): void
    {
        $a = resolve(CoverageRegistry::class);
        $b = resolve(CoverageRegistry::class);

        $this->assertInstanceOf(CoverageRegistry::class, $a);
        $this->assertSame($a, $b);
    }

    #[Test]
    public function identity_map_store_starts_empty(): void
    {
        $store = resolve(IdentityMapStore::class);

        $stats = $store->debugStats();
        $this->assertSame(0, $stats['entries']);
        $this->assertSame(0, $stats['absent']);
        $this->assertFalse($stats['disabled']);
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function jobEvents(): iterable
    {
        $job = new FakeJob;

        yield 'JobProcessing' => [new JobProcessing('sync', $job)];
        yield 'JobProcessed' => [new JobProcessed('sync', $job)];
        yield 'JobFailed' => [new JobFailed('sync', $job, new RuntimeException('test'))];
    }

    #[Test]
    #[DataProvider('jobEvents')]
    public function job_lifecycle_events_flush_every_registry(object $event): void
    {
        $this->primeAllRegistries();
        $this->assertRegistriesNotEmpty();

        Event::dispatch($event);

        $this->assertAllRegistriesFlushed();
    }

    private function primeAllRegistries(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        Post::create(['user_id' => $alice->id, 'title' => 'p1']);
        User::with('posts')->get();

        resolve(CoverageRegistry::class)->record(new CoverageEntry(
            modelClass: 'App\\Sentinel',
            connection: 'default',
            table: 'sentinels',
            scopeFingerprint: 'fp',
            region: new AndNode([]),
            columns: new ColumnSet(['*']),
            primaryKeys: [1],
            complete: true,
            version: 1,
        ));

        resolve(TransactionJournal::class)->begin('default');

        resolve(SchemaDiscovery::class)->uniqueIndexesFor(User::class);
    }

    private function assertRegistriesNotEmpty(): void
    {
        $this->assertGreaterThan(0, resolve(IdentityMapStore::class)->debugStats()['entries']);
        $this->assertGreaterThan(0, resolve(CoverageRegistry::class)->entryCount());
        $this->assertGreaterThan(0, resolve(TransactionJournal::class)->depth('default'));
        $this->assertGreaterThan(0, resolve(IdentityGraph::class)->totalEdgeCount());
        $this->assertNotEmpty($this->readSchemaCache());
    }

    private function assertAllRegistriesFlushed(): void
    {
        $this->assertSame(0, resolve(IdentityMapStore::class)->debugStats()['entries'], 'IdentityMapStore not flushed');
        $this->assertSame(0, resolve(CoverageRegistry::class)->entryCount(), 'CoverageRegistry not flushed');
        $this->assertSame(0, resolve(TransactionJournal::class)->depth('default'), 'TransactionJournal not flushed');
        $this->assertSame(0, resolve(IdentityGraph::class)->totalEdgeCount(), 'IdentityGraph not flushed');
        $this->assertEmpty($this->readSchemaCache(), 'SchemaDiscovery cache not flushed');
    }

    /** @return array<string, mixed> */
    private function readSchemaCache(): array
    {
        $reflection = new ReflectionClass(SchemaDiscovery::class);
        $property = $reflection->getProperty('uniqueIndexCache');

        /** @var array<string, mixed> $value */
        $value = $property->getValue(resolve(SchemaDiscovery::class));

        return $value;
    }
}
