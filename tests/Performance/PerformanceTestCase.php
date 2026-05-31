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

        $profileEnabled = getenv('PROFILE') === '1' && extension_loaded('excimer');
        $profiler = null;

        if ($profileEnabled) {
            $periodEnv = getenv('EXCIMER_PERIOD');
            $period = is_string($periodEnv) && is_numeric($periodEnv) ? (float) $periodEnv : 0.0001;

            $profiler = new \ExcimerProfiler;
            $profiler->setPeriod($period);
            $profiler->setEventType(EXCIMER_REAL);
            $profiler->start();
        }

        $start = hrtime(true);
        try {
            $fn();
        } finally {
            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            if ($profiler instanceof \ExcimerProfiler) {
                $profiler->stop();
                $this->writeProfileArtifacts($label, $profiler->getLog());
            }
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

    private function writeProfileArtifacts(string $label, \ExcimerLog $log): void
    {
        $dir = getenv('PROFILE_DIR') ?: 'build/profiles';

        if (! is_dir($dir) && ! mkdir($dir, 0777, true) && ! is_dir($dir)) {
            return;
        }

        $safe = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $label) ?? $label;

        // speedscope.app accepts Brendan Gregg's collapsed format natively, so we don't
        // need to also emit the proprietary speedscope JSON to get an interactive
        // flamegraph in the browser.
        $collapsed = $log->formatCollapsed();
        file_put_contents($dir.'/'.$safe.'.collapsed.txt', $collapsed);

        $top = $log->aggregateByFunction();
        uasort($top, static function (array $a, array $b): int {
            $aIncl = is_int($a['inclusive'] ?? null) ? $a['inclusive'] : 0;
            $bIncl = is_int($b['inclusive'] ?? null) ? $b['inclusive'] : 0;

            return $bIncl <=> $aIncl;
        });

        $lines = [
            sprintf('Profile: %s', $label),
            sprintf('Samples: %d', count($log)),
            '',
            sprintf('%-8s  %-8s  %s', 'incl', 'self', 'function'),
            str_repeat('-', 80),
        ];

        $shown = 0;
        foreach ($top as $fn => $stats) {
            if ($shown++ >= 40) {
                break;
            }
            $incl = is_int($stats['inclusive'] ?? null) ? $stats['inclusive'] : 0;
            $self = is_int($stats['self'] ?? null) ? $stats['self'] : 0;
            $lines[] = sprintf('%-8d  %-8d  %s', $incl, $self, $fn);
        }

        file_put_contents($dir.'/'.$safe.'.topN.txt', implode("\n", $lines)."\n");
    }
}
