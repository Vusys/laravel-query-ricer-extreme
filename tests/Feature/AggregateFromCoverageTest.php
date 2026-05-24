<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class AggregateFromCoverageTest extends TestCase
{
    private IdentityMapStore $store;

    private CoverageRegistry $registry;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->registry = resolve(CoverageRegistry::class);
        $this->store->flush();
        $this->registry->flush();
    }

    private function seedTeam(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => 10]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true, 'score' => 20]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => true, 'score' => 30]);
        User::where('active', true)->get();
    }

    private function countSql(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();

        return $count;
    }

    /** @param list<Explanation> $explanations */
    private function findExplanationByType(array $explanations, PlanType $type): Explanation
    {
        foreach ($explanations as $e) {
            if ($e->type === $type) {
                return $e;
            }
        }
        $this->fail("No explanation found for PlanType::{$type->name}");
    }

    // -------------------------------------------------------------------------
    // sum()
    // -------------------------------------------------------------------------

    #[Test]
    public function sum_is_served_from_coverage_without_sql(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $this->assertSame(60, User::where('active', true)->sum('score'));
        });

        $this->assertSame(0, $sql, 'sum() must be served from coverage without SQL');
    }

    #[Test]
    public function sum_returns_zero_for_empty_covered_region(): void
    {
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            $this->assertSame(0, User::where('active', true)->sum('score'));
        });

        $this->assertSame(0, $sql, 'sum() on empty coverage must return 0 without SQL');
    }

    #[Test]
    public function sum_captures_plan_type(): void
    {
        $this->seedTeam();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->sum('score');
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnSumFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    // -------------------------------------------------------------------------
    // min()
    // -------------------------------------------------------------------------

    #[Test]
    public function min_is_served_from_coverage_without_sql(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $this->assertSame(10, User::where('active', true)->min('score'));
        });

        $this->assertSame(0, $sql, 'min() must be served from coverage without SQL');
    }

    #[Test]
    public function min_returns_null_for_empty_covered_region(): void
    {
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            $this->assertNull(User::where('active', true)->min('score'));
        });

        $this->assertSame(0, $sql, 'min() on empty coverage must return null without SQL');
    }

    #[Test]
    public function min_captures_plan_type(): void
    {
        $this->seedTeam();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->min('score');
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnMinFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    // -------------------------------------------------------------------------
    // max()
    // -------------------------------------------------------------------------

    #[Test]
    public function max_is_served_from_coverage_without_sql(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $this->assertSame(30, User::where('active', true)->max('score'));
        });

        $this->assertSame(0, $sql, 'max() must be served from coverage without SQL');
    }

    #[Test]
    public function max_returns_null_for_empty_covered_region(): void
    {
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            $this->assertNull(User::where('active', true)->max('score'));
        });

        $this->assertSame(0, $sql, 'max() on empty coverage must return null without SQL');
    }

    #[Test]
    public function max_captures_plan_type(): void
    {
        $this->seedTeam();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->max('score');
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnMaxFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    // -------------------------------------------------------------------------
    // avg()
    // -------------------------------------------------------------------------

    #[Test]
    public function avg_is_served_from_coverage_without_sql(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $result = User::where('active', true)->avg('score');
            $this->assertIsFloat($result);
            $this->assertSame(20.0, $result);
        });

        $this->assertSame(0, $sql, 'avg() must be served from coverage without SQL');
    }

    #[Test]
    public function avg_returns_null_for_empty_covered_region(): void
    {
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            $this->assertNull(User::where('active', true)->avg('score'));
        });

        $this->assertSame(0, $sql, 'avg() on empty coverage must return null without SQL');
    }

    #[Test]
    public function avg_captures_plan_type(): void
    {
        $this->seedTeam();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->avg('score');
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnAvgFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    #[Test]
    public function average_alias_is_served_from_coverage_without_sql(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $result = User::where('active', true)->average('score');
            $this->assertSame(20.0, $result);
        });

        $this->assertSame(0, $sql, 'average() (alias for avg) must hit coverage too');
    }

    // -------------------------------------------------------------------------
    // Fallthrough paths
    // -------------------------------------------------------------------------

    #[Test]
    public function non_numeric_column_value_falls_through_to_sql(): void
    {
        // 'score' is a nullable integer cast. When the DB value is null, Eloquent's
        // integer cast returns null, which fails the !is_int && !is_float guard and
        // must force SQL fallthrough on every aggregate. A string column would also
        // exercise this path but is unportable: Postgres rejects SUM(varchar) with a
        // type error, whereas SQLite/MySQL silently coerce strings.
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => null]);
        User::where('active', true)->get();

        foreach (['sum', 'min', 'max', 'avg'] as $method) {
            $sql = $this->countSql(function () use ($method): void {
                User::where('active', true)->{$method}('score');
            });

            $this->assertSame(1, $sql, "{$method}() on a null-valued column must fall through to SQL");
        }
    }

    #[Test]
    public function without_identity_map_bypasses_coverage_for_sum(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $result = User::withoutIdentityMap()->where('active', true)->sum('score');
            $this->assertEquals(60, $result);
        });

        $this->assertGreaterThan(0, $sql, 'withoutIdentityMap() must bypass coverage');
    }

    #[Test]
    public function without_identity_map_bypasses_coverage_for_min_max_avg(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            User::withoutIdentityMap()->where('active', true)->min('score');
            User::withoutIdentityMap()->where('active', true)->max('score');
            User::withoutIdentityMap()->where('active', true)->avg('score');
        });

        $this->assertSame(3, $sql, 'Each aggregate with withoutIdentityMap() must hit SQL once');
    }

    #[Test]
    public function disabled_store_falls_through_to_sql_for_aggregates(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $this->store->disabled(function (): void {
                User::where('active', true)->sum('score');
                User::where('active', true)->min('score');
                User::where('active', true)->max('score');
                User::where('active', true)->avg('score');
            });
        });

        $this->assertSame(4, $sql);
    }

    #[Test]
    public function aggregates_fall_through_when_no_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => 10]);

        // No coverage recorded — every aggregate must hit SQL once.
        $sql = $this->countSql(function (): void {
            User::where('active', true)->sum('score');
            User::where('active', true)->min('score');
            User::where('active', true)->max('score');
            User::where('active', true)->avg('score');
        });

        $this->assertSame(4, $sql);
    }

    #[Test]
    public function expression_column_falls_through_to_sql_for_aggregates(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            User::where('active', true)->sum(DB::raw('score'));
        });

        $this->assertSame(1, $sql, 'Aggregate with non-string column expression must fall through to SQL');
    }

    // -------------------------------------------------------------------------
    // Empty-set captures explanation with sqlExecuted = false
    // -------------------------------------------------------------------------

    #[Test]
    public function empty_region_min_captures_explanation_without_sql(): void
    {
        User::where('active', true)->get();

        $explanations = $this->store->explain(function (): void {
            $this->assertNull(User::where('active', true)->min('score'));
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnMinFromCoverage);
        $this->assertFalse($hit->sqlExecuted, 'empty-set min must capture with sqlExecuted=false');
    }

    #[Test]
    public function empty_region_max_captures_explanation_without_sql(): void
    {
        User::where('active', true)->get();

        $explanations = $this->store->explain(function (): void {
            $this->assertNull(User::where('active', true)->max('score'));
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnMaxFromCoverage);
        $this->assertFalse($hit->sqlExecuted, 'empty-set max must capture with sqlExecuted=false');
    }

    #[Test]
    public function empty_region_avg_captures_explanation_without_sql(): void
    {
        User::where('active', true)->get();

        $explanations = $this->store->explain(function (): void {
            $this->assertNull(User::where('active', true)->avg('score'));
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnAvgFromCoverage);
        $this->assertFalse($hit->sqlExecuted, 'empty-set avg must capture with sqlExecuted=false');
    }

    // -------------------------------------------------------------------------
    // Partial-column coverage must force SQL for aggregates
    // -------------------------------------------------------------------------

    #[Test]
    public function partial_column_coverage_forces_aggregate_fallthrough_to_sql(): void
    {
        // Coverage records ['id', 'name'] only — 'score' is not loaded into the
        // identity map. An aggregate over 'score' must fall through to SQL
        // rather than reading null attribute values out of partially-loaded models.
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true, 'score' => 10]);
        User::where('active', true)->get(['id', 'name']);

        foreach (['sum', 'min', 'max', 'avg'] as $method) {
            $sql = $this->countSql(function () use ($method): void {
                User::where('active', true)->{$method}('score');
            });

            $this->assertSame(1, $sql, "{$method}() on a column missing from partial coverage must fall through to SQL");
        }
    }

    // -------------------------------------------------------------------------
    // count()/exists() regression
    // -------------------------------------------------------------------------

    #[Test]
    public function count_still_works_from_coverage(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $this->assertSame(3, User::where('active', true)->count());
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function exists_still_works_from_coverage(): void
    {
        $this->seedTeam();

        $sql = $this->countSql(function (): void {
            $this->assertTrue(User::where('active', true)->exists());
        });

        $this->assertSame(0, $sql);
    }
}
