<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\DataProviders;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Stress-tests the key-set rewrite path with many different shapes:
 * varying numbers of keys, hit/miss/absent ratios, ordering, deduplication,
 * and interaction with soft-deletes.
 */
#[Group('comprehensive')]
final class KeySetShapeTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    // -------------------------------------------------------------------------
    // All keys in memory — no SQL, varying set sizes
    // -------------------------------------------------------------------------

    /** @return array<string, array{int}> */
    public static function allInMemorySizeProvider(): array
    {
        return [
            '1 key' => [1],
            '2 keys' => [2],
            '3 keys' => [3],
            '5 keys' => [5],
            '10 keys' => [10],
            '20 keys' => [20],
            '50 keys' => [50],
        ];
    }

    #[DataProvider('allInMemorySizeProvider')]
    public function test_all_in_memory_issues_no_sql(int $count): void
    {
        $keys = $this->createAndWarm($count);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame(0, $queries, "whereKey({$count} known keys) must issue no SQL");
        $this->assertCount($count, $results);
    }

    // -------------------------------------------------------------------------
    // No keys in memory — one SQL for all
    // -------------------------------------------------------------------------

    /** @return array<string, array{int}> */
    public static function noneInMemorySizeProvider(): array
    {
        return [
            '1 key' => [1],
            '2 keys' => [2],
            '5 keys' => [5],
            '20 keys' => [20],
        ];
    }

    #[DataProvider('noneInMemorySizeProvider')]
    public function test_none_in_memory_issues_one_sql(int $count): void
    {
        $users = $this->createFreshModels($count);
        $keys = array_column($users, 'id');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame(1, $queries, "whereKey({$count} unknown keys) must issue exactly one SQL");
        $this->assertCount($count, $results);
    }

    // -------------------------------------------------------------------------
    // Partial hit — exactly one SQL for the unknown remainder
    // -------------------------------------------------------------------------

    /** @return array<string, array{int, int}> */
    public static function partialHitProvider(): array
    {
        return [
            '1 warm + 1 cold' => [1, 1],
            '1 warm + 2 cold' => [1, 2],
            '2 warm + 1 cold' => [2, 1],
            '3 warm + 2 cold' => [3, 2],
            '5 warm + 5 cold' => [5, 5],
            '10 warm + 10 cold' => [10, 10],
            '20 warm + 10 cold' => [20, 10],
            '25 warm + 25 cold' => [25, 25],
        ];
    }

    #[DataProvider('partialHitProvider')]
    public function test_partial_hit_issues_one_sql(int $warm, int $cold): void
    {
        $warmKeys = $this->createAndWarm($warm);
        $coldKeys = array_column($this->createFreshModels($cold), 'id');
        $keys = array_merge($warmKeys, $coldKeys);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame(1, $queries, "Partial hit ({$warm} warm + {$cold} cold): must issue exactly one SQL");
        $this->assertCount($warm + $cold, $results);
    }

    // -------------------------------------------------------------------------
    // All absent — no SQL
    // -------------------------------------------------------------------------

    /** @return array<string, array{int}> */
    public static function allAbsentProvider(): array
    {
        return [
            '1 absent key' => [1],
            '2 absent keys' => [2],
            '4 absent keys' => [4],
            '10 absent keys' => [10],
            '20 absent keys' => [20],
        ];
    }

    #[DataProvider('allAbsentProvider')]
    public function test_all_absent_issues_no_sql(int $count): void
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $missingId = 99_000_000 + $i;
            User::find($missingId);
            $keys[] = $missingId;
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame(0, $queries, "All {$count} absent keys must issue no SQL");
        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // Mixed: known + absent → no SQL
    // -------------------------------------------------------------------------

    /** @return array<string, array{int, int}> */
    public static function mixedHitAbsentProvider(): array
    {
        return [
            '1 known + 1 absent' => [1, 1],
            '2 known + 2 absent' => [2, 2],
            '3 known + 1 absent' => [3, 1],
            '5 known + 5 absent' => [5, 5],
            '10 known + 5 absent' => [10, 5],
        ];
    }

    #[DataProvider('mixedHitAbsentProvider')]
    public function test_mixed_known_and_absent_issues_no_sql(int $knownCount, int $absentCount): void
    {
        $knownKeys = $this->createAndWarm($knownCount);
        $absentKeys = [];
        for ($i = 0; $i < $absentCount; $i++) {
            $missingId = 99_100_000 + $i;
            User::find($missingId);
            $absentKeys[] = $missingId;
        }

        $keys = array_merge($knownKeys, $absentKeys);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame(0, $queries, "All {$knownCount} known + {$absentCount} absent: must issue no SQL");
        $this->assertCount($knownCount, $results);
    }

    // -------------------------------------------------------------------------
    // Result preserves input key order
    // -------------------------------------------------------------------------

    /** @return array<string, array{list<int>}> */
    public static function keyOrderProvider(): array
    {
        return [
            'ascending order' => [[0, 1, 2]],
            'descending order' => [[2, 1, 0]],
            'mixed order' => [[1, 0, 2]],
            'reverse mixed' => [[2, 0, 1]],
        ];
    }

    /**
     * @param  list<int>  $orderedIndices
     */
    #[DataProvider('keyOrderProvider')]
    public function test_result_preserves_input_key_order(array $orderedIndices): void
    {
        $allKeys = $this->createAndWarm(3);

        $requestedKeys = array_map(fn (int $i): int => $allKeys[$i], $orderedIndices);

        $results = User::whereKey($requestedKeys)->get();

        $this->assertCount(3, $results);
        $this->assertSame($requestedKeys, $results->pluck('id')->all(), 'Result order must match input key order');
    }

    // -------------------------------------------------------------------------
    // Soft-deleted key in key-set treated as absent (no SQL for soft-deleted)
    // -------------------------------------------------------------------------

    /** @return array<string, array{int, int}> */
    public static function softDeletedInKeySetProvider(): array
    {
        return [
            '1 live + 1 deleted' => [1, 1],
            '2 live + 1 deleted' => [2, 1],
            '1 live + 2 deleted' => [1, 2],
            '5 live + 3 deleted' => [5, 3],
            '10 live + 5 deleted' => [10, 5],
        ];
    }

    #[DataProvider('softDeletedInKeySetProvider')]
    public function test_soft_deleted_keys_excluded_from_result_without_sql(int $liveCount, int $deletedCount): void
    {
        $liveKeys = $this->createAndWarm($liveCount);
        $deletedKeys = [];

        for ($i = 0; $i < $deletedCount; $i++) {
            $user = User::create(['name' => 'Deleted', 'email' => 'del-'.uniqid().'@example.com']);
            User::find($user->id);
            $user->delete();
            $deletedKeys[] = $user->id;
        }

        $keys = array_merge($liveKeys, $deletedKeys);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame(0, $queries, 'Soft-deleted keys in key-set must issue no SQL');
        $this->assertCount($liveCount, $results);
    }

    // -------------------------------------------------------------------------
    // whereIn on PK column mirrors whereKey behaviour
    // -------------------------------------------------------------------------

    /** @return array<string, array{int, int}> */
    public static function whereInPkProvider(): array
    {
        return [
            'whereIn 1 warm + 1 cold' => [1, 1],
            'whereIn 2 warm + 0 cold' => [2, 0],
            'whereIn 0 warm + 2 cold' => [0, 2],
        ];
    }

    #[DataProvider('whereInPkProvider')]
    public function test_where_in_pk_rewrite(int $warm, int $cold): void
    {
        $warmKeys = $warm > 0 ? $this->createAndWarm($warm) : [];
        $coldKeys = $cold > 0 ? array_column($this->createFreshModels($cold), 'id') : [];
        $keys = array_merge($warmKeys, $coldKeys);

        if ($keys === []) {
            $this->markTestSkipped('No keys to query');
        }

        $expectedSql = ($cold > 0) ? 1 : 0;

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereIn('id', $keys)->get();

        $this->assertSame($expectedSql, $queries);
        $this->assertCount($warm + $cold, $results);
    }

    // -------------------------------------------------------------------------
    // Model returned by key-set SQL is subsequently served from memory
    // -------------------------------------------------------------------------

    #[DataProvider('noneInMemorySizeProvider')]
    public function test_key_set_sql_result_is_remembered(int $count): void
    {
        $users = $this->createFreshModels($count);
        $keys = array_column($users, 'id');

        User::whereKey($keys)->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        foreach ($keys as $key) {
            User::find($key);
        }

        $this->assertSame(0, $queries, "All {$count} models fetched by key-set SQL must be remembered");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create $count models, warm each via find(), and return their keys.
     *
     * @return list<int>
     */
    private function createAndWarm(int $count): array
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $user = User::create(['name' => 'User'.$i, 'email' => 'u'.$i.'-'.uniqid().'@example.com']);
            User::find($user->id);
            $keys[] = $user->id;
        }

        return $keys;
    }

    /**
     * Create $count models without warming the identity map.
     *
     * @return list<User>
     */
    private function createFreshModels(int $count): array
    {
        $models = [];
        for ($i = 0; $i < $count; $i++) {
            $models[] = User::create(['name' => 'Cold'.$i, 'email' => 'cold'.$i.'-'.uniqid().'@example.com']);
        }

        $this->store->flush();

        return $models;
    }
}
