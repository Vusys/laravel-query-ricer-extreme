<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
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
}
