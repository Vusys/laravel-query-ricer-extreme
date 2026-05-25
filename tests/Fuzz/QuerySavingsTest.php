<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Fuzz;

use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
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

    public function test_where_has_with_full_graph_coverage_fires_no_sql(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $userCount = mt_rand(1, 4);
            /** @var list<User> $users */
            $users = [];
            for ($i = 0; $i < $userCount; $i++) {
                $users[] = User::factory()->create();
            }

            foreach ($users as $u) {
                for ($i = 0, $count = mt_rand(1, 3); $i < $count; $i++) {
                    Post::create([
                        'user_id' => $u->id,
                        'title' => "p{$u->id}-{$i}",
                        'published' => (bool) mt_rand(0, 1),
                    ]);
                }
            }

            foreach ($users as $u) {
                $u->load('posts');
            }

            $ids = array_map(fn (User $u) => $u->id, $users);

            $with = $this->countQueries(
                fn () => User::whereKey($ids)
                    ->whereHas('posts', fn ($q) => $q->where('published', true))
                    ->get()
            );

            $oracle = $this->countQueries(fn (): mixed => IdentityMap::disabled(
                fn () => User::whereKey($ids)
                    ->whereHas('posts', fn ($q) => $q->where('published', true))
                    ->get()
            ));

            $this->assertSame(0, $with, "whereHas with full coverage must issue no SQL [seed={$seed} step={$step}]");
            $this->assertGreaterThan(0, $oracle, 'Oracle must issue SQL');
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

    /** @return array<int, User> */
    private function buildPopulation(int $count): array
    {
        return User::factory()->count($count)->create()->all();
    }
}
