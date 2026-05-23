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

final class CoverageRegistryFeatureTest extends TestCase
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedUsers(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);
        // User::all() populates identity map and records coverage for region=AND([]).
        User::all();
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

    // -------------------------------------------------------------------------
    // Coverage recording
    // -------------------------------------------------------------------------

    #[Test]
    public function all_records_coverage_entry(): void
    {
        $this->assertSame(0, $this->registry->entryCount());

        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::all();

        $this->assertSame(1, $this->registry->entryCount());
    }

    #[Test]
    public function limit_prevents_coverage_recording(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::limit(5)->get();

        $this->assertSame(0, $this->registry->entryCount());
    }

    // -------------------------------------------------------------------------
    // get() served from coverage (ReturnCollectionFromCoverage)
    // -------------------------------------------------------------------------

    #[Test]
    public function subset_get_issues_no_sql_after_all(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $results = User::where('active', true)->get();
            $this->assertCount(1, $results);
            $first = $results->first();
            $this->assertNotNull($first);
            $this->assertSame('Alice', $first->name);
        });

        $this->assertSame(0, $sql, 'Subset get() should be served from coverage without SQL');
    }

    #[Test]
    public function subset_get_returns_correct_filtered_models(): void
    {
        $this->seedUsers();

        $active = User::where('active', true)->get();
        $inactive = User::where('active', false)->get();

        $this->assertCount(1, $active);
        $this->assertCount(1, $inactive);
        $firstActive = $active->first();
        $firstInactive = $inactive->first();
        $this->assertNotNull($firstActive);
        $this->assertNotNull($firstInactive);
        $this->assertSame('Alice', $firstActive->name);
        $this->assertSame('Bob', $firstInactive->name);
    }

    #[Test]
    public function unconstrained_get_is_served_from_coverage(): void
    {
        $this->seedUsers();

        // A second unconstrained get is a subset of the recorded AndNode([]) region.
        $sql = $this->countSql(function (): void {
            $results = User::all();
            $this->assertCount(2, $results);
        });

        $this->assertSame(0, $sql);
    }

    // -------------------------------------------------------------------------
    // count() served from coverage (ReturnCountFromCoverage)
    // -------------------------------------------------------------------------

    #[Test]
    public function count_issues_no_sql_after_coverage_seeded(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $count = User::where('active', true)->count();
            $this->assertSame(1, $count);
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function count_star_uses_coverage_total(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $this->assertSame(2, User::query()->count());
        });

        $this->assertSame(0, $sql);
    }

    // -------------------------------------------------------------------------
    // exists() served from coverage (ReturnExistsFromCoverage)
    // -------------------------------------------------------------------------

    #[Test]
    public function exists_returns_true_from_coverage_when_matches_exist(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $this->assertTrue(User::where('active', true)->exists());
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function exists_returns_false_from_coverage_when_no_matches(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::all();

        // No inactive users were loaded, so active=false region evaluates to empty.
        $sql = $this->countSql(function (): void {
            $this->assertFalse(User::where('active', false)->exists());
        });

        $this->assertSame(0, $sql);
    }

    // -------------------------------------------------------------------------
    // pluck() served from coverage (ReturnPluckFromCoverage)
    // -------------------------------------------------------------------------

    #[Test]
    public function pluck_issues_no_sql_after_coverage_seeded(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $names = User::where('active', true)->pluck('name');
            $this->assertSame(['Alice'], $names->all());
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function pluck_with_key_issues_no_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::all();

        $sql = $this->countSql(function () use ($alice): void {
            $result = User::where('active', true)->pluck('name', 'id');
            $this->assertSame('Alice', $result[$alice->id]);
        });

        $this->assertSame(0, $sql);
    }

    // -------------------------------------------------------------------------
    // first() served from coverage (ReturnFirstFromCoverage)
    // -------------------------------------------------------------------------

    #[Test]
    public function first_with_single_match_issues_no_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $user = User::where('active', true)->first();
            $this->assertNotNull($user);
            $this->assertSame('Alice', $user->name);
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function first_with_order_by_numeric_column_issues_no_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        User::all();

        $sql = $this->countSql(function () use ($alice): void {
            $first = User::where('active', true)->orderBy('id')->first();
            $this->assertNotNull($first);
            $this->assertSame($alice->id, $first->id);
        });

        $this->assertSame(0, $sql);
    }

    #[Test]
    public function first_with_multiple_results_no_order_by_falls_through_to_sql(): void
    {
        // Two matches but no ORDER BY → cannot determine ordering from memory → SQL.
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            User::query()->first();
        });

        $this->assertSame(1, $sql, 'first() with 2+ matches and no ORDER BY must fall through to SQL');
    }

    // -------------------------------------------------------------------------
    // Empty coverage recording
    // -------------------------------------------------------------------------

    #[Test]
    public function empty_region_is_recorded_and_served(): void
    {
        // No users exist. Querying active users returns [] and records an empty region.
        User::where('active', true)->get();

        $this->assertSame(1, $this->registry->entryCount());

        // Second call should be served from coverage (no SQL).
        $sql = $this->countSql(function (): void {
            $results = User::where('active', true)->get();
            $this->assertCount(0, $results);
        });

        $this->assertSame(0, $sql, 'Empty coverage region should be served without SQL');
    }

    #[Test]
    public function empty_coverage_count_returns_zero(): void
    {
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            $this->assertSame(0, User::where('active', true)->count());
        });

        $this->assertSame(0, $sql);
    }

    // -------------------------------------------------------------------------
    // Subset miss — falls through to SQL
    // -------------------------------------------------------------------------

    #[Test]
    public function non_subset_query_falls_through_to_sql(): void
    {
        // 'Charlie' is not in the identity map, so the evaluator returns Unknown → SQL.
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $result = User::where('name', 'Charlie')->first();
            $this->assertNull($result);
        });

        $this->assertSame(1, $sql, 'Query with unknown model should fall through to SQL');
    }

    // -------------------------------------------------------------------------
    // Invalidation on model save
    // -------------------------------------------------------------------------

    #[Test]
    public function save_flushes_coverage_for_model_class(): void
    {
        $this->seedUsers();
        $this->assertSame(1, $this->registry->entryCount());

        $alice = User::where('name', 'Alice')->first();
        $this->assertNotNull($alice);
        $this->store->flush();
        $this->registry->flush();
        User::all();

        $alice->name = 'Alicia';
        $alice->save();

        $this->assertSame(0, $this->registry->entryCount());
    }

    #[Test]
    public function save_causes_next_query_to_hit_sql(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::all();

        $alice->name = 'Alicia';
        $alice->save();

        // Coverage is gone, so next query must hit SQL.
        $sql = $this->countSql(function (): void {
            User::where('active', true)->get();
        });

        $this->assertGreaterThan(0, $sql, 'After save, coverage is invalidated and SQL should run');
    }

    // -------------------------------------------------------------------------
    // Invalidation on mass update
    // -------------------------------------------------------------------------

    #[Test]
    public function mass_update_flushes_coverage(): void
    {
        $this->seedUsers();
        $this->assertSame(1, $this->registry->entryCount());

        User::where('active', false)->update(['active' => true]);

        $this->assertSame(0, $this->registry->entryCount());
    }

    #[Test]
    public function mass_update_causes_next_query_to_hit_sql(): void
    {
        $this->seedUsers();

        User::where('active', false)->update(['active' => true]);

        $sql = $this->countSql(function (): void {
            User::where('active', true)->get();
        });

        $this->assertGreaterThan(0, $sql, 'After mass update, coverage must be re-fetched from SQL');
    }

    // -------------------------------------------------------------------------
    // Invalidation on mass delete (soft)
    // -------------------------------------------------------------------------

    #[Test]
    public function mass_delete_flushes_coverage(): void
    {
        $this->seedUsers();
        $this->assertSame(1, $this->registry->entryCount());

        User::where('active', false)->delete();

        $this->assertSame(0, $this->registry->entryCount());
    }

    #[Test]
    public function mass_delete_causes_next_query_to_hit_sql(): void
    {
        $this->seedUsers();

        User::where('active', false)->delete();

        $sql = $this->countSql(function (): void {
            User::all();
        });

        $this->assertGreaterThan(0, $sql);
    }

    // -------------------------------------------------------------------------
    // Singleton registration
    // -------------------------------------------------------------------------

    #[Test]
    public function service_provider_registers_coverage_registry_singleton(): void
    {
        $r1 = resolve(CoverageRegistry::class);
        $r2 = resolve(CoverageRegistry::class);

        $this->assertSame($r1, $r2);
    }

    // -------------------------------------------------------------------------
    // PlanType is captured for served queries
    // -------------------------------------------------------------------------

    #[Test]
    public function coverage_get_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->get();
        });

        $hit = array_values(array_filter($explanations, static fn (Explanation $e): bool => $e->type === PlanType::ReturnCollectionFromCoverage));
        $this->assertNotEmpty($hit);
        $this->assertFalse($hit[0]->sqlExecuted);
    }

    #[Test]
    public function coverage_count_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->count();
        });

        $hit = array_values(array_filter($explanations, static fn (Explanation $e): bool => $e->type === PlanType::ReturnCountFromCoverage));
        $this->assertNotEmpty($hit);
        $this->assertFalse($hit[0]->sqlExecuted);
    }

    #[Test]
    public function coverage_exists_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->exists();
        });

        $hit = array_values(array_filter($explanations, static fn (Explanation $e): bool => $e->type === PlanType::ReturnExistsFromCoverage));
        $this->assertNotEmpty($hit);
        $this->assertFalse($hit[0]->sqlExecuted);
    }

    #[Test]
    public function coverage_pluck_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->pluck('name');
        });

        $hit = array_values(array_filter($explanations, static fn (Explanation $e): bool => $e->type === PlanType::ReturnPluckFromCoverage));
        $this->assertNotEmpty($hit);
        $this->assertFalse($hit[0]->sqlExecuted);
    }

    #[Test]
    public function coverage_first_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->first();
        });

        $hit = array_values(array_filter($explanations, static fn (Explanation $e): bool => $e->type === PlanType::ReturnFirstFromCoverage));
        $this->assertNotEmpty($hit);
        $this->assertFalse($hit[0]->sqlExecuted);
    }
}
