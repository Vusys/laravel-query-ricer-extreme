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
 * Cross-product of model lifecycle states × query methods.
 *
 * Each lifecycle state is reached by a fixed setup sequence, then every
 * query method in the matrix is exercised to confirm whether SQL fires
 * and what result shape is returned.
 */
#[Group('comprehensive')]
final class LifecycleStateTest extends TestCase
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
    // Lifecycle state × find() — SQL count and return value
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, bool|null, int}>
     *
     * [lifecycle, expectModel (true=User, false=null, null=skip), expectedSqlCount]
     */
    public static function lifecycleFindProvider(): array
    {
        return [
            // Exists state: model is warmed in map
            'exists — find hits memory' => ['exists',       true,  0],

            // Absent state: first find went to SQL and returned null
            'absent — second find skips SQL' => ['absent',       false, 0],

            // SoftDeleted: model was soft-deleted after being warmed
            'soft-deleted — find returns null no SQL' => ['soft-deleted', false, 0],

            // Restored: soft-deleted then restored
            'restored — find hits memory' => ['restored',     true,  0],

            // Saved: model modified and saved; still in memory
            'saved — find hits memory' => ['saved',        true,  0],

            // ForceDeleted: entry cleared; next find goes to SQL
            'force-deleted — find hits SQL' => ['force-deleted', false, 1],
        ];
    }

    #[DataProvider('lifecycleFindProvider')]
    public function test_find_after_lifecycle_state(string $lifecycle, ?bool $expectModel, int $expectedSql): void
    {
        [$user, $key] = $this->reachState($lifecycle);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $result = User::find($key);

        $this->assertSame($expectedSql, $queries, "find() after state={$lifecycle}: SQL count mismatch");

        if ($expectModel === true) {
            $this->assertInstanceOf(User::class, $result);
            $this->assertSame($key, $result->id);
        } else {
            $this->assertNull($result);
        }
    }

    // -------------------------------------------------------------------------
    // Lifecycle state × whereKey()->get()
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, int, int}> */
    public static function lifecycleWhereKeyProvider(): array
    {
        return [
            'exists — collection from memory' => ['exists',        0, 1],
            'absent — empty collection no SQL' => ['absent',        0, 0],
            'soft-deleted — empty collection no SQL' => ['soft-deleted',  0, 0],
            'restored — collection from memory' => ['restored',      0, 1],
            'saved — collection from memory' => ['saved',         0, 1],
            'force-deleted — SQL, empty' => ['force-deleted', 1, 0],
        ];
    }

    #[DataProvider('lifecycleWhereKeyProvider')]
    public function test_where_key_get_after_lifecycle_state(string $lifecycle, int $expectedSql, int $expectedCount): void
    {
        [$user, $key] = $this->reachState($lifecycle);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey([$key])->get();

        $this->assertSame($expectedSql, $queries, "whereKey()->get() after state={$lifecycle}: SQL count mismatch");
        $this->assertCount($expectedCount, $results);
    }

    // -------------------------------------------------------------------------
    // Lifecycle state × find() with predicate filter in memory
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool, int, int}> */
    public static function lifecyclePredicateProvider(): array
    {
        return [
            'exists active=true — match no SQL' => ['exists',   true,  0, 1],
            'exists active=false — reject no SQL' => ['exists',   false, 0, 0],
            'restored active=true — match no SQL' => ['restored', true,  0, 1],
            'saved active=true — match no SQL' => ['saved',    true,  0, 1],
        ];
    }

    #[DataProvider('lifecyclePredicateProvider')]
    public function test_predicate_filter_after_lifecycle_state(string $lifecycle, bool $predicate, int $expectedSql, int $expectedCount): void
    {
        [$user, $key] = $this->reachState($lifecycle);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey([$key])->where('active', $predicate)->get();

        $this->assertSame($expectedSql, $queries, "predicate after state={$lifecycle}: SQL count mismatch");
        $this->assertCount($expectedCount, $results);
    }

    // -------------------------------------------------------------------------
    // Multi-model: mix of lifecycle states in a single key-set query
    // -------------------------------------------------------------------------

    /** @return array<string, array{list<string>, int, int}> */
    public static function mixedLifecycleKeySetProvider(): array
    {
        return [
            'exists + absent → no SQL, 1 result' => [['exists', 'absent'],                        0, 1],
            'exists + soft-deleted → no SQL, 1 result' => [['exists', 'soft-deleted'],             0, 1],
            'exists + saved → no SQL, 2 results' => [['exists', 'saved'],                          0, 2],
            'absent + soft-deleted → no SQL, 0 results' => [['absent', 'soft-deleted'],            0, 0],
            'exists + absent + soft-deleted → no SQL, 1 result' => [['exists', 'absent', 'soft-deleted'],  0, 1],
            'exists + saved + absent → no SQL, 2 results' => [['exists', 'saved', 'absent'],       0, 2],
            'restored + saved + absent → no SQL, 2 results' => [['restored', 'saved', 'absent'],   0, 2],
            'exists + soft-deleted + saved → no SQL, 2 results' => [['exists', 'soft-deleted', 'saved'], 0, 2],
        ];
    }

    /**
     * @param  list<string>  $states
     */
    #[DataProvider('mixedLifecycleKeySetProvider')]
    public function test_mixed_lifecycle_states_in_key_set(array $states, int $expectedSql, int $expectedCount): void
    {
        $keys = [];
        foreach ($states as $state) {
            [, $key] = $this->reachState($state);
            $keys[] = $key;
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $results = User::whereKey($keys)->get();

        $this->assertSame($expectedSql, $queries, 'Mixed lifecycle key-set: SQL count mismatch');
        $this->assertCount($expectedCount, $results);
    }

    // -------------------------------------------------------------------------
    // Sequence: create → delete → restore → re-delete
    // -------------------------------------------------------------------------

    public function test_delete_restore_delete_sequence(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        User::find($user->id);

        $user->delete();
        $this->assertNull(User::find($user->id));

        $user->restore();
        $afterRestore = User::find($user->id);
        $this->assertInstanceOf(User::class, $afterRestore);

        $user->delete();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::find($user->id);
        $this->assertSame(0, $queries, 'After second delete, absence record must prevent SQL');
    }

    // -------------------------------------------------------------------------
    // Sequence: save mutates tracked attribute; predicate re-evaluates correctly
    // -------------------------------------------------------------------------

    public function test_save_attribute_change_reflects_in_predicate_evaluation(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com', 'active' => true]);
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $before = User::whereKey([$user->id])->where('active', true)->get();
        $this->assertCount(1, $before);
        $this->assertSame(0, $queries);

        $user->active = false;
        $user->save();

        $queries = 0;

        $after = User::whereKey([$user->id])->where('active', true)->get();
        $this->assertCount(0, $after);
        $this->assertSame(0, $queries, 'Updated attribute must be re-evaluated in memory');
    }

    // -------------------------------------------------------------------------
    // State machine helper
    // -------------------------------------------------------------------------

    /**
     * Reaches the requested lifecycle state and returns the model + its PK.
     * Every state starts with a fresh store (store was flushed in setUp).
     *
     * @return array{User, int}
     */
    private function reachState(string $state): array
    {
        $user = User::create([
            'name' => 'Alice-'.$state,
            'email' => 'alice-'.uniqid().'-'.$state.'@example.com',
            'active' => true,
        ]);

        $key = $user->id;

        match ($state) {
            'exists' => User::find($key),
            'absent' => (static function () use ($key): void {
                User::find(99999990 + $key);
            })(),
            'soft-deleted' => (function () use ($user, $key): void {
                User::find($key);
                $user->delete();
                User::find($key);
            })(),
            'restored' => (function () use ($user, $key): void {
                User::find($key);
                $user->delete();
                $user->restore();
            })(),
            'saved' => (function () use ($user, $key): void {
                User::find($key);
                $user->name = 'Updated';
                $user->save();
            })(),
            'force-deleted' => (function () use ($user, $key): void {
                User::find($key);
                $user->forceDelete();
            })(),
            default => throw new \InvalidArgumentException("Unknown state: {$state}"),
        };

        if ($state === 'absent') {
            $key = 99999990 + $key;
        }

        return [$user, $key];
    }
}
