<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Fuzz;

use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;

#[Group('fuzzer')]
final class QueryCorrectnessTest extends FuzzerTestCase
{
    /**
     * Oracle: identity-map find() must return same record as bypassed find().
     */
    public function test_find_by_primary_key_matches_oracle(): void
    {
        /** @var list<User> $population */
        $population = [];

        $this->eachSeed(function (int $seed, int $step) use (&$population): void {
            $store = resolve(IdentityMapStore::class);

            if ($step === 0) {
                $population = $this->buildPopulation($seed, $step);
            }

            $store->flush();

            // 60 % chance to use a known ID, 40 % a guaranteed-absent one
            $useKnown = $population !== [] && mt_rand(0, 9) < 6;
            $id = $useKnown
                ? $population[mt_rand(0, count($population) - 1)]->id
                : mt_rand(900_000, 999_999);

            $found = User::find($id);
            $actualId = ($found instanceof User) ? $found->id : null;

            $oracleRaw = $store->disabled(fn () => User::find($id));
            $oracleId = ($oracleRaw instanceof User) ? $oracleRaw->id : null;

            $this->assertSame($oracleId, $actualId, "find({$id})");
        });
    }

    /**
     * Oracle: identity-map whereKey(...)->get() must return same ID set as bypassed query.
     * Exercises the partial-hit key-set rewrite path.
     */
    public function test_where_key_collection_matches_oracle(): void
    {
        /** @var list<User> $population */
        $population = [];

        $this->eachSeed(function (int $seed, int $step) use (&$population): void {
            $store = resolve(IdentityMapStore::class);

            if ($step === 0) {
                $population = $this->buildPopulation($seed, $step);
            }

            $store->flush();

            // Build a query set: random subset of known IDs + 1-2 unknown IDs
            $knownIds = array_map(fn (User $u) => $u->id, $population);
            $unknownIds = [mt_rand(900_000, 999_999), mt_rand(800_000, 899_999)];

            $queryIds = array_unique(array_merge(
                $knownIds !== [] ? array_slice($knownIds, 0, mt_rand(0, count($knownIds))) : [],
                array_slice($unknownIds, 0, mt_rand(0, 2)),
            ));

            if ($queryIds === []) {
                $queryIds = [$unknownIds[0]];
            }

            $actual = User::whereKey($queryIds)->get()->pluck('id')->sort()->values()->all();

            $oracle = $store->disabled(
                fn () => User::whereKey($queryIds)->get()->pluck('id')->sort()->values()->all()
            );

            $this->assertSame($oracle, $actual, 'whereKey([...])');
        });
    }

    /**
     * Oracle: identity-map whereKey+predicate must return same ID set as bypassed query.
     * Exercises the attribute predicate evaluation on cached entries.
     */
    public function test_active_predicate_via_key_set_matches_oracle(): void
    {
        /** @var list<User> $population */
        $population = [];

        $this->eachSeed(function (int $seed, int $step) use (&$population): void {
            $store = resolve(IdentityMapStore::class);

            if ($step === 0) {
                $population = $this->buildPopulation($seed, $step);
            }

            if ($population === []) {
                return;
            }

            $store->flush();

            // Warm a random prefix of the population so some entries are cached, some are not
            $warmCount = mt_rand(0, count($population));
            foreach (array_slice($population, 0, $warmCount) as $user) {
                User::find($user->id);
            }

            $activeValue = (bool) mt_rand(0, 1);
            $ids = array_map(fn (User $u) => $u->id, $population);

            $actual = User::whereKey($ids)
                ->where('active', $activeValue)
                ->get()
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $oracle = $store->disabled(
                fn () => User::whereKey($ids)
                    ->where('active', $activeValue)
                    ->get()
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all()
            );

            $this->assertSame($oracle, $actual, 'whereKey([...])->where(active, ...)');
        });
    }

    /** @return list<User> */
    private function buildPopulation(int $seed, int $step): array
    {
        $count = mt_rand(1, 5);
        $users = [];

        for ($i = 0; $i < $count; $i++) {
            $user = User::create([
                'name' => 'FuzzUser'.$i,
                'email' => sprintf('fuzz-%d-%d-%d@fuzz.test', $seed, $step, $i),
                'active' => (bool) mt_rand(0, 1),
            ]);

            // 30 % chance to soft-delete
            if (mt_rand(0, 9) < 3) {
                $user->delete();
            }

            $users[] = $user;
        }

        return $users;
    }
}
