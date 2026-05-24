<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Fuzz;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Tests\Models\User;

#[Group('fuzzer')]
final class QuerySavingsTest extends FuzzerTestCase
{
    public function test_find_warm_entry_fires_no_sql(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $population = $this->buildPopulation(mt_rand(1, 5));
            $user = $population[mt_rand(0, count($population) - 1)];

            $with = $this->countQueries(fn () => User::find($user->id));
            $oracle = $this->countQueries(fn (): mixed => IdentityMap::disabled(fn () => User::find($user->id)));

            $this->assertSame(0, $with, "find(warm id={$user->id}) must issue no SQL [seed={$seed} step={$step}]");
            $this->assertSame(1, $oracle, 'Oracle must always issue SQL');
        });
    }

    public function test_where_key_all_warm_fires_no_sql(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $population = $this->buildPopulation(mt_rand(1, 10));
            $ids = array_map(fn (User $u) => $u->id, $population);

            $with = $this->countQueries(fn () => User::whereKey($ids)->get());
            $oracle = $this->countQueries(fn (): mixed => IdentityMap::disabled(fn () => User::whereKey($ids)->get()));

            $this->assertSame(0, $with, 'whereKey(all warm) must issue no SQL');
            $this->assertSame(1, $oracle, 'Oracle must always issue SQL');
        });
    }

    public function test_absent_tracking_fires_no_sql_on_repeat(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $absentIds = [];
            $count = mt_rand(1, 5);
            for ($i = 0; $i < $count; $i++) {
                $id = 900_000 + ($seed % 50_000) + $i;
                User::find($id);
                $absentIds[] = $id;
            }

            $with = $this->countQueries(function () use ($absentIds): void {
                foreach ($absentIds as $id) {
                    User::find($id);
                }
            });

            $oracle = $this->countQueries(function () use ($absentIds): void {
                IdentityMap::disabled(function () use ($absentIds): void {
                    foreach ($absentIds as $id) {
                        User::find($id);
                    }
                });
            });

            $this->assertSame(0, $with, 'Repeated absent finds must issue no SQL');
            $this->assertSame(count($absentIds), $oracle, 'Oracle must issue one SQL per absent lookup');
        });
    }

    private function countQueries(Closure $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $fn();
        } finally {
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        return $count;
    }

    /** @return list<User> */
    private function buildPopulation(int $count): array
    {
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $users[] = User::create([
                'name' => 'SavingsUser'.$i,
                'email' => sprintf('savings-%d-%d@fuzz.test', mt_rand(), $i),
                'active' => (bool) mt_rand(0, 1),
            ]);
        }

        return $users;
    }
}
