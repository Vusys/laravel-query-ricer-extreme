<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\DataProviders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\Models\UuidUser;
use Vusys\QueryRicerExtreme\Tests\TestCase;

#[Group('comprehensive')]
final class PkTypeTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    /** @return array<string, array{class-string<User>|class-string<UuidUser>}> */
    public static function pkModelProvider(): array
    {
        return [
            'integer pk (User)' => [User::class],
            'uuid pk (UuidUser)' => [UuidUser::class],
        ];
    }

    /** @return array<string, array{class-string<User>|class-string<UuidUser>, int}> */
    public static function pkModelWithCountProvider(): array
    {
        return [
            'integer pk — 1 model' => [User::class,     1],
            'integer pk — 3 models' => [User::class,     3],
            'integer pk — 5 models' => [User::class,     5],
            'uuid pk — 1 model' => [UuidUser::class, 1],
            'uuid pk — 3 models' => [UuidUser::class, 3],
            'uuid pk — 5 models' => [UuidUser::class, 5],
        ];
    }

    /**
     * @param  class-string<User>|class-string<UuidUser>  $modelClass
     * @param  array<string, mixed>  $overrides
     */
    private function createModel(string $modelClass, array $overrides = []): User|UuidUser
    {
        $attrs = array_merge(['name' => 'Test', 'email' => 'test-'.Str::random(8).'@example.com'], $overrides);

        if ($modelClass === UuidUser::class && ! isset($attrs['id'])) {
            $attrs['id'] = (string) Str::uuid();
        }

        return $modelClass::create($attrs);
    }

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    private function missingKey(string $modelClass): int|string
    {
        return $modelClass === UuidUser::class ? (string) Str::uuid() : 999_999_999;
    }

    // -------------------------------------------------------------------------
    // Basic cache hit — same instance, no second SQL
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_find_returns_same_instance_on_second_call(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $this->store->flush();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $first = $modelClass::find($model->getKey());
        $second = $modelClass::find($model->getKey());

        $this->assertSame(1, $queries);
        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // Absence tracking prevents second SQL
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_absence_tracking_prevents_second_sql(string $modelClass): void
    {
        $unknownKey = $this->missingKey($modelClass);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $first = $modelClass::find($unknownKey);
        $second = $modelClass::find($unknownKey);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(1, $queries);
    }

    // -------------------------------------------------------------------------
    // Soft delete lifecycle
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_soft_delete_causes_subsequent_finds_to_skip_sql(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $key = $model->getKey();

        $modelClass::find($key);
        $model->delete();

        $afterDelete = $modelClass::find($key);
        $this->assertNull($afterDelete);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::find($key);
        $this->assertNull($result);
        $this->assertSame(0, $queries, 'Second find after soft-delete must use absence record');
    }

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_restore_makes_model_findable_without_sql(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $key = $model->getKey();

        $modelClass::find($key);
        $model->delete();
        $model->restore();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::find($key);
        $this->assertInstanceOf($modelClass, $result);
        $this->assertSame(0, $queries, 'Restored model must be served from memory');
    }

    // -------------------------------------------------------------------------
    // Force delete lifecycle
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_force_delete_clears_entry_so_next_find_queries_db(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $key = $model->getKey();

        $modelClass::find($key);
        $model->forceDelete();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::find($key);
        $this->assertNull($result);
        $this->assertSame(1, $queries, 'Force-delete does not record absent; next find must hit SQL');
    }

    // -------------------------------------------------------------------------
    // Key-set rewrite: partial hit issues exactly one SQL
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_key_set_partial_hit_issues_only_one_sql(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $modelClass::find($model->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = $modelClass::whereKey([$model->getKey(), $this->missingKey($modelClass)])->get();

        $this->assertSame(1, $queries);
        $this->assertCount(1, $results);
    }

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_key_set_all_in_memory_issues_no_sql(string $modelClass): void
    {
        $a = $this->createModel($modelClass);
        $b = $this->createModel($modelClass);

        $modelClass::find($a->getKey());
        $modelClass::find($b->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = $modelClass::whereKey([$a->getKey(), $b->getKey()])->get();

        $this->assertSame(0, $queries);
        $this->assertCount(2, $results);
    }

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_key_set_all_absent_issues_no_sql(string $modelClass): void
    {
        $missing1 = $this->missingKey($modelClass);
        $missing2 = $modelClass === UuidUser::class ? (string) Str::uuid() : 999_999_998;

        $modelClass::find($missing1);
        $modelClass::find($missing2);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = $modelClass::whereKey([$missing1, $missing2])->get();

        $this->assertSame(0, $queries);
        $this->assertCount(0, $results);
    }

    // -------------------------------------------------------------------------
    // findMany — mirrors whereKey behaviour
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_find_many_all_in_memory_issues_no_sql(string $modelClass): void
    {
        $a = $this->createModel($modelClass);
        $b = $this->createModel($modelClass);

        $modelClass::find($a->getKey());
        $modelClass::find($b->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::findMany([$a->getKey(), $b->getKey()]);

        $this->assertSame(0, $queries);
        $this->assertCount(2, $result);
    }

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_find_many_partial_hit_issues_one_sql(string $modelClass): void
    {
        $a = $this->createModel($modelClass);
        $b = $this->createModel($modelClass);
        $this->store->flush();

        $modelClass::find($a->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = $modelClass::findMany([$a->getKey(), $b->getKey()]);

        $this->assertSame(1, $queries);
        $this->assertCount(2, $result);
    }

    // -------------------------------------------------------------------------
    // Bulk warm-up: N models all served from memory after initial load
    // -------------------------------------------------------------------------

    /**
     * @param  class-string<User>|class-string<UuidUser>  $modelClass
     */
    #[DataProvider('pkModelWithCountProvider')]
    public function test_bulk_find_all_in_memory_after_initial_load(string $modelClass, int $count): void
    {
        /** @var list<int|string> $keys */
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $model = $this->createModel($modelClass);
            $keys[] = $model->getKey();
        }

        foreach ($keys as $key) {
            $modelClass::find($key);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = $modelClass::whereKey($keys)->get();

        $this->assertSame(0, $queries, "All {$count} models should be served from memory");
        $this->assertCount($count, $results);
    }

    // -------------------------------------------------------------------------
    // Save → still served from memory
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_saved_model_still_served_from_memory(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $modelClass::find($model->getKey());

        $model->name = 'Updated';
        $model->save();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $found = $modelClass::find($model->getKey());
        $this->assertSame(0, $queries);
        $this->assertInstanceOf($modelClass, $found);
        $this->assertSame('Updated', $found->name);
    }

    // -------------------------------------------------------------------------
    // withoutIdentityMap always bypasses cache
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_without_identity_map_bypasses_cache(string $modelClass): void
    {
        $model = $this->createModel($modelClass);
        $modelClass::find($model->getKey());

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $modelClass::query()->withoutIdentityMap()->find($model->getKey());

        $this->assertSame(1, $queries, 'withoutIdentityMap must bypass identity map for both int and UUID PK');
    }

    // -------------------------------------------------------------------------
    // Fallthrough SQL marks model all-columns-known for subsequent find
    // -------------------------------------------------------------------------

    /** @param  class-string<User>|class-string<UuidUser>  $modelClass */
    #[DataProvider('pkModelProvider')]
    public function test_fallthrough_sql_marks_model_all_columns_known(string $modelClass): void
    {
        $model = $this->createModel($modelClass);

        $modelClass::where('name', 'Test')->get();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $found = $modelClass::find($model->getKey());

        $this->assertSame(0, $queries, 'Model returned from fallthrough SQL must be all-columns-known');
        $this->assertNotNull($found);
    }
}
