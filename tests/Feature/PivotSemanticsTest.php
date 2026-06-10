<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Graph\IdentityGraph;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class PivotSemanticsTest extends TestCase
{
    private IdentityMapStore $store;

    private IdentityGraph $graph;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->graph = resolve(IdentityGraph::class);
        $this->store->flush();
        $this->graph->flush();
    }

    private function countQueries(callable $cb): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $cb();

        return $count;
    }

    /**
     * PHP's loose `==` treats numeric strings as equal ('0123' == '123'), but a
     * SQL string comparison on the pivot column does not. The memory path must
     * mirror SQL, not PHP coercion.
     */
    #[Test]
    public function string_pivot_equality_does_not_coerce_numeric_strings(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $tag = Tag::create(['name' => 'T', 'priority' => 1]);
        $post->tags()->attach($tag, ['active' => true, 'priority' => 1, 'role' => '0123']);

        $post->load('tags'); // warm graph coverage

        $ricer = null;
        $queries = $this->countQueries(function () use ($post, &$ricer): void {
            $ricer = $post->tags()->wherePivot('role', '123')->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => $post->tags()->wherePivot('role', '123')->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame([], $ricer, "'0123' must not match '123' under SQL string comparison");
        $this->assertSame(0, $queries, 'pivot equality must still be served from memory');
    }

    #[Test]
    public function string_pivot_equality_matches_exact_value_from_memory(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $tag = Tag::create(['name' => 'Editor', 'priority' => 1]);
        $post->tags()->attach($tag, ['active' => true, 'priority' => 1, 'role' => 'editor']);

        $post->load('tags');

        $ricer = null;
        $queries = $this->countQueries(function () use ($post, &$ricer): void {
            $ricer = $post->tags()->wherePivot('role', 'editor')->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => $post->tags()->wherePivot('role', 'editor')->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame(['Editor'], $ricer);
        $this->assertSame(0, $queries, 'exact string pivot equality must be served from memory');
    }

    #[Test]
    public function string_pivot_in_does_not_coerce_numeric_strings(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $tag = Tag::create(['name' => 'T', 'priority' => 1]);
        $post->tags()->attach($tag, ['active' => true, 'priority' => 1, 'role' => '0123']);

        $post->load('tags');

        $ricer = $post->tags()->wherePivotIn('role', ['123', '456'])->get()->pluck('name')->all();
        $oracle = IdentityMap::disabled(
            fn () => $post->tags()->wherePivotIn('role', ['123', '456'])->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame([], $ricer);
    }

    /**
     * On a case-insensitive collation (MySQL/MariaDB *_ci) SQL matches
     * 'admin' = 'ADMIN'; PHP `==` rejects it. The memory path must agree with
     * SQL — matching when the collation is case-insensitive, and never silently
     * diverging.
     */
    #[Test]
    public function case_varied_string_pivot_matches_sql_on_mysql(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            $this->markTestSkipped('Case-insensitive collation behaviour is exercised on MySQL/MariaDB CI cells.');
        }

        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $tag = Tag::create(['name' => 'AdminTag', 'priority' => 1]);
        $post->tags()->attach($tag, ['active' => true, 'priority' => 1, 'role' => 'admin']);

        $post->load('tags');

        $ricer = $post->tags()->wherePivot('role', 'ADMIN')->get()->pluck('name')->all();
        $oracle = IdentityMap::disabled(
            fn () => $post->tags()->wherePivot('role', 'ADMIN')->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer, 'memory-served pivot string comparison must match SQL collation behaviour');
    }
}
