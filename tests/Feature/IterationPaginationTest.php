<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Differential coverage for the iteration / pagination helpers that the package
 * does not override (chunk, chunkById, cursor, lazy, lazyById, each, paginate,
 * simplePaginate, cursorPaginate). They route through get()/getModels() or use
 * limit/offset that trips the structural-hazard bail-outs, so they *should* be
 * safe — these tests prove it against the oracle and document which paths elide.
 */
final class IterationPaginationTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    /** @return list<int> */
    private function seedUsers(int $count = 6): array
    {
        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = User::factory()->create(['name' => sprintf('U%02d', $i), 'active' => true])->id;
        }

        return $ids;
    }

    private function warm(): void
    {
        // Records full coverage + warms every entry so memory could, in
        // principle, serve a subsequent read.
        User::query()->get();
    }

    #[Test]
    public function chunk_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $collected = [];
        User::query()->orderBy('id')->chunk(2, function ($users) use (&$collected): void {
            foreach ($users as $u) {
                $collected[] = $u->id;
            }
        });

        $oracle = IdentityMap::disabled(function (): array {
            $out = [];
            User::query()->orderBy('id')->chunk(2, function ($users) use (&$out): void {
                foreach ($users as $u) {
                    $out[] = $u->id;
                }
            });

            return $out;
        });

        $this->assertSame($oracle, $collected);
    }

    #[Test]
    public function chunk_by_id_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $collected = [];
        User::query()->chunkById(2, function ($users) use (&$collected): void {
            foreach ($users as $u) {
                $collected[] = $u->id;
            }
        });

        $oracle = IdentityMap::disabled(function (): array {
            $out = [];
            User::query()->chunkById(2, function ($users) use (&$out): void {
                foreach ($users as $u) {
                    $out[] = $u->id;
                }
            });

            return $out;
        });

        $this->assertSame($oracle, $collected);
    }

    #[Test]
    public function cursor_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $collected = User::query()->orderBy('id')->cursor()->map(fn ($u): int => $u->id)->all();

        $oracle = IdentityMap::disabled(
            fn (): array => User::query()->orderBy('id')->cursor()->map(fn ($u): int => $u->id)->all()
        );

        $this->assertSame($oracle, $collected);
    }

    #[Test]
    public function lazy_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $collected = User::query()->orderBy('id')->lazy(2)->map(fn ($u): int => $u->id)->all();

        $oracle = IdentityMap::disabled(
            fn (): array => User::query()->orderBy('id')->lazy(2)->map(fn ($u): int => $u->id)->all()
        );

        $this->assertSame($oracle, $collected);
    }

    #[Test]
    public function lazy_by_id_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $collected = User::query()->lazyById(2)->map(fn ($u): int => $u->id)->all();

        $oracle = IdentityMap::disabled(
            fn (): array => User::query()->lazyById(2)->map(fn ($u): int => $u->id)->all()
        );

        $this->assertSame($oracle, $collected);
    }

    #[Test]
    public function each_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $collected = [];
        User::query()->orderBy('id')->each(function ($u) use (&$collected): void {
            $collected[] = $u->id;
        }, 2);

        $oracle = IdentityMap::disabled(function (): array {
            $out = [];
            User::query()->orderBy('id')->each(function ($u) use (&$out): void {
                $out[] = $u->id;
            }, 2);

            return $out;
        });

        $this->assertSame($oracle, $collected);
    }

    #[Test]
    public function paginate_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $page = User::query()->orderBy('id')->paginate(perPage: 2, page: 2);
        $ricer = ['total' => $page->total(), 'ids' => $page->pluck('id')->all()];

        $oracle = IdentityMap::disabled(function (): array {
            $p = User::query()->orderBy('id')->paginate(perPage: 2, page: 2);

            return ['total' => $p->total(), 'ids' => $p->pluck('id')->all()];
        });

        $this->assertSame($oracle, $ricer);
    }

    #[Test]
    public function simple_paginate_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $page = User::query()->orderBy('id')->simplePaginate(perPage: 2, page: 2);
        $ricer = $page->pluck('id')->all();

        $oracle = IdentityMap::disabled(
            fn (): array => User::query()->orderBy('id')->simplePaginate(perPage: 2, page: 2)->pluck('id')->all()
        );

        $this->assertSame($oracle, $ricer);
    }

    #[Test]
    public function cursor_paginate_matches_oracle(): void
    {
        $this->seedUsers();
        $this->warm();

        $page = User::query()->orderBy('id')->cursorPaginate(perPage: 2);
        $ricer = $page->pluck('id')->all();

        $oracle = IdentityMap::disabled(
            fn (): array => User::query()->orderBy('id')->cursorPaginate(perPage: 2)->pluck('id')->all()
        );

        $this->assertSame($oracle, $ricer);
    }

    /**
     * The classic mutation-during-iteration hazard: chunkById walks the table in
     * key order while the callback mass-updates each chunk. The package must
     * evict/invalidate so the final state — and the rows visited — match SQL.
     */
    #[Test]
    public function chunk_by_id_with_mass_update_inside_callback_visits_every_row_once(): void
    {
        $ids = $this->seedUsers(7);
        $this->warm();

        $visited = [];
        User::query()->chunkById(2, function ($users) use (&$visited): void {
            foreach ($users as $u) {
                $visited[] = $u->id;
            }
            User::query()->whereIn('id', $users->pluck('id')->all())->update(['active' => false]);
        });

        // chunkById seeks by id > lastId, so a mass update that does not touch the
        // key column must neither skip nor revisit rows.
        sort($visited);
        $this->assertSame($ids, $visited, 'chunkById must visit every row exactly once despite the in-callback mass update');

        // Post-mutation read must reflect the writes, not stale memory.
        $active = User::query()->orderBy('id')->get()->pluck('active')->all();
        $this->assertSame(array_fill(0, 7, false), $active);

        $oracleActive = IdentityMap::disabled(
            fn (): array => User::query()->orderBy('id')->get()->pluck('active')->all()
        );
        $this->assertSame($oracleActive, $active);
    }

    /**
     * Documents the elision behaviour: the iteration helpers all use
     * limit/offset (or key-seek windows), which trip the structural-hazard
     * bail-outs, so they execute SQL rather than serving from memory. This test
     * pins that contract — if a future change makes one of them elide, the
     * differential tests above must continue to hold.
     */
    #[Test]
    public function iteration_helpers_execute_sql_and_do_not_elide(): void
    {
        $this->seedUsers();
        $this->warm();

        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        User::query()->orderBy('id')->chunk(2, fn (): null => null);

        $this->assertGreaterThan(0, $count, 'chunk() windows via limit/offset and must execute SQL');
    }
}
