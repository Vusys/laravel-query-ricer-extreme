<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Coverage\CoverageRegistry;
use Vusys\QueryRicerExtreme\Enums\PlanType;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
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

    #[Test]
    public function distinct_prevents_coverage_recording(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        User::distinct()->get();

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

    #[Test]
    public function first_returns_null_from_empty_coverage_without_sql(): void
    {
        // No users — coverage records empty region.
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            $result = User::where('active', true)->first();
            $this->assertNull($result);
        });

        $this->assertSame(0, $sql, 'first() on empty coverage must not fall through to SQL');
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
        // Only active=true users are covered; a query for active=false is outside
        // that region, so no covering entry exists and SQL must run.
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::where('active', true)->get();

        $sql = $this->countSql(function (): void {
            User::where('active', false)->get();
        });

        $this->assertSame(1, $sql, 'Query outside recorded region should fall through to SQL');
    }

    #[Test]
    public function first_on_non_existent_user_served_from_coverage_without_sql(): void
    {
        // All users loaded → coverage knows no user named Charlie exists.
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $result = User::where('name', 'Charlie')->first();
            $this->assertNull($result);
        });

        $this->assertSame(0, $sql, 'first() for absent model should be served from coverage without SQL');
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

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnCollectionFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    #[Test]
    public function coverage_count_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->count();
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnCountFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    #[Test]
    public function coverage_exists_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->exists();
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnExistsFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    #[Test]
    public function coverage_pluck_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->pluck('name');
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnPluckFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    #[Test]
    public function coverage_first_captures_plan_type_with_no_sql(): void
    {
        $this->seedUsers();

        $explanations = $this->store->explain(function (): void {
            User::where('active', true)->first();
        });

        $hit = $this->findExplanationByType($explanations, PlanType::ReturnFirstFromCoverage);
        $this->assertFalse($hit->sqlExecuted);
    }

    // -------------------------------------------------------------------------
    // count() fallthrough paths
    // -------------------------------------------------------------------------

    #[Test]
    public function count_with_identity_map_disabled_falls_through_to_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            User::query()->withoutIdentityMap()->count();
        });

        $this->assertGreaterThan(0, $sql, 'count() with withoutIdentityMap() must always hit SQL');
    }

    #[Test]
    public function count_with_store_disabled_falls_through_to_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $this->store->disabled(function (): void {
                User::where('active', true)->count();
            });
        });

        $this->assertGreaterThan(0, $sql, 'count() when store is disabled must hit SQL');
    }

    #[Test]
    public function count_with_column_arg_falls_through_to_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            User::where('active', true)->count('id');
        });

        $this->assertSame(1, $sql, 'count() with a column argument must fall through to SQL');
    }

    #[Test]
    public function count_falls_through_when_no_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        $sql = $this->countSql(function (): void {
            User::where('active', true)->count();
        });

        $this->assertSame(1, $sql, 'count() before any coverage is seeded must hit SQL');
    }

    // -------------------------------------------------------------------------
    // pluck() fallthrough paths
    // -------------------------------------------------------------------------

    #[Test]
    public function pluck_with_identity_map_disabled_falls_through_to_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            User::query()->withoutIdentityMap()->pluck('name');
        });

        $this->assertGreaterThan(0, $sql, 'pluck() with withoutIdentityMap() must always hit SQL');
    }

    #[Test]
    public function pluck_with_store_disabled_falls_through_to_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            $this->store->disabled(function (): void {
                User::where('active', true)->pluck('name');
            });
        });

        $this->assertGreaterThan(0, $sql, 'pluck() when store is disabled must hit SQL');
    }

    #[Test]
    public function pluck_falls_through_when_no_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        $sql = $this->countSql(function (): void {
            User::where('active', true)->pluck('name');
        });

        $this->assertSame(1, $sql, 'pluck() before any coverage is seeded must hit SQL');
    }

    #[Test]
    public function pluck_with_expression_column_falls_through_to_sql(): void
    {
        $this->seedUsers();

        $sql = $this->countSql(function (): void {
            User::where('active', true)->pluck(DB::raw('name'));
        });

        $this->assertSame(1, $sql, 'pluck() with a non-string expression column must fall through to SQL');
    }

    // -------------------------------------------------------------------------
    // Partial column coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function partial_column_coverage_falls_through_for_uncovered_column(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::get(['id', 'name']);

        $sql = $this->countSql(function (): void {
            User::get(['id', 'email']);
        });

        $this->assertSame(1, $sql, 'get() requesting a column absent from the coverage ColumnSet must fall through to SQL');
    }

    #[Test]
    public function partial_column_coverage_served_when_columns_match(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::get(['id', 'name']);

        $sql = $this->countSql(function (): void {
            $users = User::get(['id', 'name']);
            $this->assertCount(1, $users);
        });

        $this->assertSame(0, $sql, 'get() requesting covered columns should be served without SQL');
    }

    #[Test]
    public function full_select_falls_through_when_coverage_recorded_with_partial_columns(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::get(['id', 'name']);

        // A wildcard request cannot be served by a partial-column ColumnSet.
        $sql = $this->countSql(function (): void {
            User::all();
        });

        $this->assertSame(1, $sql, 'Full select must fall through when coverage only has partial columns');
    }

    // -------------------------------------------------------------------------
    // Identity map miss during coverage serve
    // -------------------------------------------------------------------------

    #[Test]
    public function get_falls_through_when_identity_map_flushed_after_coverage(): void
    {
        $this->seedUsers();
        $this->store->flush();

        // Coverage registry still has the AndNode([]) entry but the identity map is empty.
        $sql = $this->countSql(function (): void {
            User::where('active', true)->get();
        });

        $this->assertGreaterThan(0, $sql, 'get() must fall through to SQL when identity entries are absent');
    }

    // -------------------------------------------------------------------------
    // sortForFirst edge cases
    // -------------------------------------------------------------------------

    #[Test]
    public function first_with_raw_order_by_falls_through_to_sql(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        User::all();

        $sql = $this->countSql(function (): void {
            User::where('active', true)->orderByRaw('id ASC')->first();
        });

        $this->assertSame(1, $sql, 'first() with a raw ORDER BY must fall through to SQL');
    }

    #[Test]
    public function first_with_string_sort_column_falls_through_to_sql(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        User::all();

        $sql = $this->countSql(function (): void {
            User::where('active', true)->orderBy('name')->first();
        });

        $this->assertSame(1, $sql, 'first() ordered by a non-numeric column must fall through to SQL');
    }

    // -------------------------------------------------------------------------
    // Non-string SELECT columns (withCount / selectRaw)
    // -------------------------------------------------------------------------

    #[Test]
    public function withcount_not_served_from_coverage(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 1', 'published' => true]);

        User::where('active', true)->get();
        $this->assertGreaterThan(0, $this->registry->entryCount(), 'Pre-condition: coverage must exist');

        $this->store->flush();
        $this->registry->flush();
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => true]);
        User::where('active', true)->get();
        $this->store->flush();

        $sql = $this->countSql(function (): void {
            $users = User::withCount('posts')->where('active', true)->get();
            foreach ($users as $u) {
                $this->assertTrue(
                    $u->offsetExists('posts_count'),
                    "User #{$u->id} is missing posts_count — was served from coverage without SQL",
                );
            }
        });

        $this->assertGreaterThan(0, $sql, 'withCount query must execute SQL, not be served from coverage');
    }

    #[Test]
    public function selectraw_not_served_from_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        User::where('active', true)->get();
        $this->store->flush();

        $sql = $this->countSql(function (): void {
            $results = User::selectRaw('*, 42 as magic_number')->where('active', true)->get();
            foreach ($results as $u) {
                $this->assertTrue(
                    $u->offsetExists('magic_number'),
                    "User #{$u->id} missing magic_number — served from coverage without SQL",
                );
            }
        });

        $this->assertGreaterThan(0, $sql, 'selectRaw query must not be served from coverage');
    }

    #[Test]
    public function withcount_virtual_column_not_satisfied_by_all_columns_known(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'Post 1', 'published' => true]);

        User::all();

        $users = User::withCount('posts')->get();

        foreach ($users as $u) {
            $this->assertTrue(
                $u->offsetExists('posts_count'),
                "User #{$u->id} must have posts_count — virtual column must come from SQL",
            );
        }
    }

    // -------------------------------------------------------------------------
    // GROUP BY must not create coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function group_by_query_does_not_create_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);

        $before = $this->registry->entryCount();

        User::select('active')->groupBy('active')->get();

        $this->assertSame($before, $this->registry->entryCount(), 'GROUP BY must not create a coverage entry');
    }

    // -------------------------------------------------------------------------
    // LIMIT prevents subsequent query from being served incorrectly
    // -------------------------------------------------------------------------

    #[Test]
    public function limited_query_does_not_pollute_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);
        User::create(['name' => 'Carol', 'email' => 'carol@example.com', 'active' => true]);

        User::where('active', true)->limit(2)->get();

        $sql = $this->countSql(function (): void {
            $users = User::where('active', true)->get();
            $this->assertCount(3, $users, 'All 3 active users must be returned — limited query must not pollute coverage');
        });

        $this->assertGreaterThan(0, $sql, 'Query after a limited fetch must execute SQL');
    }

    // -------------------------------------------------------------------------
    // Mass-update invalidates coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function mass_update_invalidates_coverage(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);

        User::where('active', true)->get();
        $this->assertGreaterThan(0, $this->registry->entryCount());

        User::where('active', true)->update(['active' => false]);

        $this->assertSame(0, $this->registry->entryCount(), 'Coverage must be flushed after mass update');

        $sql = $this->countSql(function (): void {
            $users = User::where('active', false)->get();
            $this->assertCount(2, $users, 'Both users must appear as inactive after mass update');
        });

        $this->assertGreaterThan(0, $sql, 'Query after mass update must hit the database');
    }

    // -------------------------------------------------------------------------
    // sole() correctness via coverage
    // -------------------------------------------------------------------------

    #[Test]
    public function sole_throws_multiple_records_found_when_coverage_has_multiple_matches(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => true]);

        User::where('active', true)->get();

        $this->expectException(MultipleRecordsFoundException::class);

        User::where('active', true)->sole();
    }

    #[Test]
    public function sole_throws_model_not_found_when_coverage_has_no_matches(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => false]);

        User::all();

        $this->expectException(ModelNotFoundException::class);

        User::where('active', true)->sole();
    }

    #[Test]
    public function sole_returns_model_when_coverage_has_exactly_one_match(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);
        User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'active' => false]);

        User::all();

        $found = User::where('active', true)->sole();
        $this->assertSame($alice->id, $found->id);
    }
}
