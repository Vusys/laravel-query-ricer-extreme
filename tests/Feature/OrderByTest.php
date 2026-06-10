<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class OrderByTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
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

    #[Test]
    public function warm_key_set_respects_order_by(): void
    {
        $a = User::factory()->create(['name' => 'Charlie']);
        $b = User::factory()->create(['name' => 'Alice']);
        $c = User::factory()->create(['name' => 'Bob']);

        User::find($a->id);
        User::find($b->id);
        User::find($c->id);

        $queries = 0;
        $ricer = null;
        $queries = $this->countQueries(function () use ($a, $b, $c, &$ricer): void {
            $ricer = User::whereKey([$a->id, $b->id, $c->id])->orderBy('name')->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => User::whereKey([$a->id, $b->id, $c->id])->orderBy('name')->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertGreaterThan(0, $queries, 'warm key-set with orderBy must bail to SQL');
    }

    #[Test]
    public function warm_key_set_respects_latest(): void
    {
        $a = User::factory()->create(['name' => 'Charlie']);
        $b = User::factory()->create(['name' => 'Alice']);
        $c = User::factory()->create(['name' => 'Bob']);

        User::find($a->id);
        User::find($b->id);
        User::find($c->id);

        $ricer = User::whereKey([$a->id, $b->id, $c->id])->latest('id')->get()->pluck('id')->all();
        $oracle = IdentityMap::disabled(
            fn () => User::whereKey([$a->id, $b->id, $c->id])->latest('id')->get()->pluck('id')->all()
        );

        $this->assertSame($oracle, $ricer);
    }

    #[Test]
    public function coverage_served_get_respects_order_by(): void
    {
        User::factory()->create(['name' => 'Charlie', 'active' => true]);
        User::factory()->create(['name' => 'Alice', 'active' => true]);
        User::factory()->create(['name' => 'Bob', 'active' => true]);

        User::where('active', true)->get();

        $ricer = null;
        $queries = $this->countQueries(function () use (&$ricer): void {
            $ricer = User::where('active', true)->orderBy('name')->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => User::where('active', true)->orderBy('name')->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertGreaterThan(0, $queries, 'coverage-served get with orderBy must bail to SQL');
    }

    #[Test]
    public function coverage_served_pluck_respects_oldest(): void
    {
        User::factory()->create(['name' => 'Charlie', 'active' => true]);
        User::factory()->create(['name' => 'Alice', 'active' => true]);
        User::factory()->create(['name' => 'Bob', 'active' => true]);

        User::where('active', true)->get();

        $ricer = User::where('active', true)->oldest('name')->pluck('id')->all();
        $oracle = IdentityMap::disabled(
            fn () => User::where('active', true)->oldest('name')->pluck('id')->all()
        );

        $this->assertSame($oracle, $ricer);
    }

    #[Test]
    public function loaded_has_many_respects_order_by(): void
    {
        $user = User::factory()->create();
        $user->posts()->create(['title' => 'Charlie', 'published' => true]);
        $user->posts()->create(['title' => 'Alice', 'published' => true]);
        $user->posts()->create(['title' => 'Bob', 'published' => true]);

        $user->load('posts');

        $ricer = null;
        $queries = $this->countQueries(function () use ($user, &$ricer): void {
            $ricer = $user->posts()->orderBy('title')->get()->pluck('title')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => $user->posts()->orderBy('title')->get()->pluck('title')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertGreaterThan(0, $queries, 'loaded hasMany with orderBy must bail to SQL');
    }

    #[Test]
    public function loaded_morph_many_respects_order_by(): void
    {
        $user = User::factory()->create();
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'Charlie']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'Alice']);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'Bob']);

        $user->load('comments');

        $ricer = null;
        $queries = $this->countQueries(function () use ($user, &$ricer): void {
            $ricer = $user->comments()->orderBy('body')->get()->pluck('body')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => $user->comments()->orderBy('body')->get()->pluck('body')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertGreaterThan(0, $queries, 'loaded morphMany with orderBy must bail to SQL');
    }

    #[Test]
    public function loaded_belongs_to_many_respects_where_pivot_order_by(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);

        $tagC = Tag::create(['name' => 'Charlie', 'priority' => 1]);
        $tagA = Tag::create(['name' => 'Alice', 'priority' => 2]);
        $tagB = Tag::create(['name' => 'Bob', 'priority' => 3]);

        $post->tags()->attach($tagC, ['active' => true, 'priority' => 1]);
        $post->tags()->attach($tagA, ['active' => true, 'priority' => 2]);
        $post->tags()->attach($tagB, ['active' => true, 'priority' => 3]);

        $post->load('tags');

        $ricer = null;
        $queries = $this->countQueries(function () use ($post, &$ricer): void {
            $ricer = $post->tags()->wherePivot('active', true)->orderBy('name')->get()->pluck('name')->all();
        });

        $oracle = IdentityMap::disabled(
            fn () => $post->tags()->wherePivot('active', true)->orderBy('name')->get()->pluck('name')->all()
        );

        $this->assertSame($oracle, $ricer);
        $this->assertGreaterThan(0, $queries, 'loaded belongsToMany with wherePivot + orderBy must bail to SQL');
    }
}
