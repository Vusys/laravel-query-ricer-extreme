<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Performance;

use Illuminate\Support\Facades\DB;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\TestCase;

abstract class PerformanceTestCase extends TestCase
{
    protected function bench(string $label, callable $fn): void
    {
        $this->expectNotToPerformAssertions();

        resolve(IdentityMapStore::class)->flush();
        resolve(CoverageRegistry::class)->flush();
        resolve(IdentityGraph::class)->flush();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = hrtime(true);
        try {
            $fn();
        } finally {
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $queryWord = $queries === 1 ? 'query' : 'queries';

        fwrite(STDERR, sprintf(
            "::bench-end::   %s  %-60s  %.3f ms  %d %s\n",
            $label,
            $label,
            $elapsed,
            $queries,
            $queryWord,
        ));
    }
}
