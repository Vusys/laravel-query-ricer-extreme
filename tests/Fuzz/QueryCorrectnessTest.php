<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Fuzz;

use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
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
            if ($step === 0) {
                $population = $this->buildPopulation();
            }

            IdentityMap::flush();

            // 60 % chance to use a known ID, 40 % a guaranteed-absent one
            $useKnown = $population !== [] && mt_rand(0, 9) < 6;
            $id = $useKnown
                ? $population[mt_rand(0, count($population) - 1)]->id
                : mt_rand(900_000, 999_999);

            $found = User::find($id);
            $actualId = ($found instanceof User) ? $found->id : null;

            $oracleRaw = IdentityMap::disabled(fn () => User::find($id));
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
            if ($step === 0) {
                $population = $this->buildPopulation();
            }

            IdentityMap::flush();

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

            $oracle = IdentityMap::disabled(
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
            if ($step === 0) {
                $population = $this->buildPopulation();
            }

            if ($population === []) {
                return;
            }

            IdentityMap::flush();

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

            $oracle = IdentityMap::disabled(
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

    /**
     * Oracle: whereHas('posts', closure) with graph coverage must match bypassed query.
     */
    public function test_where_has_with_graph_coverage_matches_oracle(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            // Users first, then posts (graph invalidation is per-class).
            /** @var list<User> $users */
            $users = [];
            for ($i = 0, $count = mt_rand(2, 5); $i < $count; $i++) {
                $users[] = User::factory()->create();
            }

            foreach ($users as $u) {
                for ($i = 0, $count = mt_rand(0, 3); $i < $count; $i++) {
                    Post::create([
                        'user_id' => $u->id,
                        'title' => "p{$u->id}-{$i}",
                        'published' => (bool) mt_rand(0, 1),
                    ]);
                }
            }

            // Warm a random subset by loading their posts → builds graph coverage.
            foreach ($users as $u) {
                if (mt_rand(0, 1) === 1) {
                    $u->load('posts');
                }
            }

            $userIds = array_map(fn (User $u) => $u->id, $users);
            $predicateValue = (bool) mt_rand(0, 1);

            $actual = User::whereKey($userIds)
                ->whereHas('posts', fn ($q) => $q->where('published', $predicateValue))
                ->get()
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $oracle = IdentityMap::disabled(
                fn () => User::whereKey($userIds)
                    ->whereHas('posts', fn ($q) => $q->where('published', $predicateValue))
                    ->get()
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all()
            );

            $this->assertSame($oracle, $actual, 'whereHas should match oracle');
        });
    }

    /**
     * Oracle: whereDoesntHave inverts the membership semantics correctly.
     */
    public function test_where_doesnt_have_matches_oracle(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            /** @var list<User> $users */
            $users = [];
            for ($i = 0, $count = mt_rand(2, 5); $i < $count; $i++) {
                $users[] = User::factory()->create();
            }

            foreach ($users as $u) {
                for ($i = 0, $count = mt_rand(0, 3); $i < $count; $i++) {
                    Post::create([
                        'user_id' => $u->id,
                        'title' => "p{$u->id}-{$i}",
                        'published' => (bool) mt_rand(0, 1),
                    ]);
                }
            }

            foreach ($users as $u) {
                if (mt_rand(0, 1) === 1) {
                    $u->load('posts');
                }
            }

            $userIds = array_map(fn (User $u) => $u->id, $users);
            $predicateValue = (bool) mt_rand(0, 1);

            $actual = User::whereKey($userIds)
                ->whereDoesntHave('posts', fn ($q) => $q->where('published', $predicateValue))
                ->get()
                ->pluck('id')
                ->sort()
                ->values()
                ->all();

            $oracle = IdentityMap::disabled(
                fn () => User::whereKey($userIds)
                    ->whereDoesntHave('posts', fn ($q) => $q->where('published', $predicateValue))
                    ->get()
                    ->pluck('id')
                    ->sort()
                    ->values()
                    ->all()
            );

            $this->assertSame($oracle, $actual, 'whereDoesntHave should match oracle');
        });
    }

    /**
     * Oracle: ORDER BY must be honoured on coverage-served and warm key-set
     * reads. Result ORDER is asserted (no sort()/set-equality) so a memory path
     * that serves rows in recorded order instead of sorted order diverges.
     * Regression net for ROADMAP 1.1.
     */
    public function test_ordered_reads_match_oracle(): void
    {
        /** @var list<User> $population */
        $population = [];

        $this->eachSeed(function (int $seed, int $step) use (&$population): void {
            if ($step === 0) {
                $population = $this->buildPopulation();
            }

            if ($population === []) {
                return;
            }

            IdentityMap::flush();

            [$col, $dir] = $this->randomUserOrder();

            // Coverage-served path: warm full coverage, then read ordered.
            User::query()->get();

            $coverageActual = User::query()->orderBy($col, $dir)->orderBy('id')->get()->pluck('id')->all();
            $coverageOracle = IdentityMap::disabled(
                fn () => User::query()->orderBy($col, $dir)->orderBy('id')->get()->pluck('id')->all()
            );
            $this->assertSame($coverageOracle, $coverageActual, "coverage ordered get by {$col} {$dir}");

            // Warm key-set path: warm every key via find(), then read ordered.
            IdentityMap::flush();
            $ids = array_map(fn (User $u): int => $u->id, $population);
            foreach ($ids as $id) {
                User::find($id);
            }

            $keySetActual = User::whereKey($ids)->orderBy($col, $dir)->orderBy('id')->get()->pluck('id')->all();
            $keySetOracle = IdentityMap::disabled(
                fn () => User::whereKey($ids)->orderBy($col, $dir)->orderBy('id')->get()->pluck('id')->all()
            );
            $this->assertSame($keySetOracle, $keySetActual, "warm key-set ordered get by {$col} {$dir}");
        });
    }

    /**
     * Oracle: with()->first() served from coverage must apply eager loads.
     * Regression net for ROADMAP 1.2.
     */
    public function test_eager_load_on_first_matches_oracle(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            /** @var list<User> $users */
            $users = [];
            for ($i = 0, $count = mt_rand(1, 4); $i < $count; $i++) {
                $users[] = User::factory()->create(['active' => true]);
            }

            foreach ($users as $u) {
                for ($i = 0, $count = mt_rand(0, 3); $i < $count; $i++) {
                    Post::create(['user_id' => $u->id, 'title' => "p{$u->id}-{$i}", 'published' => (bool) mt_rand(0, 1)]);
                }
            }

            User::where('active', true)->get(); // record coverage

            // orderBy('id') keeps first() served from coverage via sortForFirst.
            $first = User::with('posts')->where('active', true)->orderBy('id')->first();

            $actual = $first instanceof User
                ? ['id' => $first->id, 'loaded' => $first->relationLoaded('posts'), 'posts' => $first->posts->pluck('id')->sort()->values()->all()]
                : null;

            $oracle = IdentityMap::disabled(function (): ?array {
                $f = User::with('posts')->where('active', true)->orderBy('id')->first();

                return $f instanceof User
                    ? ['id' => $f->id, 'loaded' => $f->relationLoaded('posts'), 'posts' => $f->posts->pluck('id')->sort()->values()->all()]
                    : null;
            });

            $this->assertSame($oracle, $actual, "with()->first() [seed={$seed} step={$step}]");
        });
    }

    /**
     * Oracle: a pool of extra-predicate shapes over warm + cold key-set entries
     * must match SQL, including comparisons against NULL columns.
     */
    public function test_extra_predicate_shapes_match_oracle(): void
    {
        /** @var list<User> $population */
        $population = [];

        $this->eachSeed(function (int $seed, int $step) use (&$population): void {
            if ($step === 0) {
                $population = $this->buildPopulation();
            }

            if ($population === []) {
                return;
            }

            IdentityMap::flush();

            // Warm a random prefix so some keys are cached, some cold.
            $warmCount = mt_rand(0, count($population));
            foreach (array_slice($population, 0, $warmCount) as $user) {
                User::find($user->id);
            }

            $ids = array_map(fn (User $u): int => $u->id, $population);
            $applyShape = $this->randomPredicateShape();

            $actual = $applyShape(User::whereKey($ids))->get()->pluck('id')->sort()->values()->all();
            $oracle = IdentityMap::disabled(
                fn () => $applyShape(User::whereKey($ids))->get()->pluck('id')->sort()->values()->all()
            );

            $this->assertSame($oracle, $actual, "extra predicate shape [seed={$seed} step={$step}]");
        });
    }

    /**
     * Oracle: case-varied / numeric-string pivot equality must match SQL string
     * comparison, not PHP loose ==. Regression net for ROADMAP 1.3.
     */
    public function test_pivot_string_predicate_matches_oracle(): void
    {
        $roles = ['admin', 'Admin', 'ADMIN', 'editor', '0123', '123', 'a', 'A'];

        $this->eachSeed(function (int $seed, int $step) use ($roles): void {
            IdentityMap::flush();

            $user = User::factory()->create();
            $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);

            for ($i = 0, $count = mt_rand(1, 4); $i < $count; $i++) {
                $tag = Tag::create(['name' => "t{$i}", 'priority' => $i]);
                $post->tags()->attach($tag, [
                    'active' => (bool) mt_rand(0, 1),
                    'priority' => $i,
                    'role' => $roles[mt_rand(0, count($roles) - 1)],
                ]);
            }

            $post->load('tags'); // warm graph coverage

            $probe = $roles[mt_rand(0, count($roles) - 1)];

            $actual = $post->tags()->wherePivot('role', $probe)->get()->pluck('id')->sort()->values()->all();
            $oracle = IdentityMap::disabled(
                fn () => $post->tags()->wherePivot('role', $probe)->get()->pluck('id')->sort()->values()->all()
            );

            $this->assertSame($oracle, $actual, "wherePivot(role, {$probe}) [seed={$seed} step={$step}]");
        });
    }

    /** @return array{0: string, 1: 'asc'|'desc'} */
    private function randomUserOrder(): array
    {
        $columns = ['name', 'email', 'score', 'bio', 'id'];
        $col = $columns[mt_rand(0, count($columns) - 1)];
        $dir = mt_rand(0, 1) === 1 ? 'desc' : 'asc';

        return [$col, $dir];
    }

    /**
     * Returns a closure that appends one randomly-chosen predicate shape to a
     * User builder. Operator pool spans =, !=, <, >=, whereIn, whereNull,
     * whereBetween over both the integer `score` (nullable) and `active` columns.
     *
     * @return \Closure(Builder<User>): Builder<User>
     */
    private function randomPredicateShape(): \Closure
    {
        $shape = mt_rand(0, 6);
        $value = mt_rand(0, 100);
        $low = mt_rand(0, 50);
        $high = $low + mt_rand(0, 50);

        return match ($shape) {
            0 => fn ($q) => $q->where('score', '=', $value),
            1 => fn ($q) => $q->where('score', '!=', $value),
            2 => fn ($q) => $q->where('score', '<', $value),
            3 => fn ($q) => $q->where('score', '>=', $value),
            4 => fn ($q) => $q->whereIn('score', [$value, $low, $high]),
            5 => fn ($q) => $q->whereNull('bio'),
            default => fn ($q) => $q->whereBetween('score', [$low, $high]),
        };
    }

    /** @return list<User> */
    private function buildPopulation(): array
    {
        $users = [];

        for ($i = 0, $count = mt_rand(1, 5); $i < $count; $i++) {
            $user = User::factory()->create(['active' => (bool) mt_rand(0, 1)]);

            if (mt_rand(0, 9) < 3) {
                $user->delete();
            }

            $users[] = $user;
        }

        return $users;
    }
}
