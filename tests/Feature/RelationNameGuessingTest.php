<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Vusys\QueryRicerExtreme\HasIdentityMap;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\ResolverUser;
use Vusys\QueryRicerExtreme\Tests\Models\TraitHostedUser;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * `HasIdentityMap` guesses the relation name from the call stack so that loaded
 * hasMany/morphMany relations can be served from memory. The guess is memoised
 * per structural signature (declaring class + related class + keys) to avoid a
 * backtrace on every relation access. These tests pin three properties:
 *
 *  1. trait-hosted relation methods are still named correctly (served from memory);
 *  2. two relations sharing one signature never diverge from SQL, even though the
 *     memo can only hold one name per signature;
 *  3. resolveRelationUsing() relations (a closure frame, not a named method) still
 *     return exactly what SQL would.
 */
final class RelationNameGuessingTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The memo is a per-class trait static; clear the classes under test so
        // query-count assertions do not depend on cross-test ordering.
        foreach ([User::class, TraitHostedUser::class, ResolverUser::class] as $class) {
            $property = new ReflectionProperty($class, 'relationNameMemo');
            $property->setValue(null, []);
        }
        unset($property);
    }

    #[Test]
    public function trait_hosted_relation_is_named_correctly_and_served_from_memory(): void
    {
        $user = TraitHostedUser::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'Kept', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'Dropped', 'published' => false]);

        $user->load('authoredPosts');

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $ricer = $user->authoredPosts()->where('published', true)->get()->pluck('title')->all();

        $this->assertSame(0, $queries, 'a trait-hosted relation must still be named correctly so it serves from memory');
        $this->assertSame(['Kept'], $ricer);
    }

    #[Test]
    public function two_relations_sharing_a_signature_never_diverge(): void
    {
        $user = TraitHostedUser::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'One', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'Two', 'published' => false]);

        // Loading authoredPosts memoises the shared signature under that name.
        $user->load('authoredPosts');

        // everyPost shares the exact signature; the memo hands it the other name,
        // but the row set is identical so the result must equal SQL.
        $ricer = $user->everyPost()->get()->pluck('title')->sort()->values()->all();
        $oracle = IdentityMap::disabled(
            fn (): array => TraitHostedUser::query()->whereKey($user->id)->first()
                ?->everyPost()->get()->pluck('title')->sort()->values()->all() ?? []
        );

        $this->assertSame($oracle, $ricer);
        $this->assertSame(['One', 'Two'], $ricer);
    }

    #[Test]
    public function distinct_signatures_keep_distinct_names(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $user->comments()->create(['body' => 'hi']);

        $user->load(['posts', 'comments']);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $posts = $user->posts()->where('published', true)->get()->pluck('title')->all();
        $comments = $user->comments()->get()->pluck('body')->all();

        $this->assertSame(0, $queries, 'a hasMany and a morphMany on the same model must each keep their own name');
        $this->assertSame(['P'], $posts);
        $this->assertSame(['hi'], $comments);
    }

    #[Test]
    public function resolve_relation_using_relation_matches_sql(): void
    {
        ResolverUser::resolveRelationUsing(
            'dynamicPosts',
            fn (ResolverUser $model) => $model->hasMany(Post::class, 'user_id'),
        );

        $user = ResolverUser::create(['name' => 'R', 'email' => 'r@example.com']);
        Post::create(['user_id' => $user->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $user->id, 'title' => 'P2', 'published' => false]);

        $ricer = $this->dynamicPostTitles($user->id);
        $oracle = IdentityMap::disabled(fn (): array => $this->dynamicPostTitles($user->id));

        $this->assertSame($oracle, $ricer, 'a closure-frame relation name must never corrupt the result');
        $this->assertSame(['P1', 'P2'], $ricer);
    }

    /** @return list<string> */
    private function dynamicPostTitles(int $userId): array
    {
        $user = ResolverUser::query()->whereKey($userId)->first();
        $this->assertInstanceOf(ResolverUser::class, $user);
        $user->load('dynamicPosts');

        $relation = $user->getRelation('dynamicPosts');
        $this->assertInstanceOf(EloquentCollection::class, $relation);

        /** @var list<string> $titles */
        $titles = $relation->pluck('title')->sort()->values()->all();

        return $titles;
    }

    #[Test]
    public function the_memo_persists_across_instances_of_the_same_class(): void
    {
        $first = TraitHostedUser::create(['name' => 'A', 'email' => 'a@example.com']);
        $first->authoredPosts()->getRelated();

        $property = new ReflectionProperty(TraitHostedUser::class, 'relationNameMemo');
        $memo = $property->getValue();

        $this->assertIsArray($memo);
        $this->assertContains('authoredPosts', $memo, 'the guessed name is cached after the first instantiation');
    }

    #[Test]
    public function the_memo_is_isolated_per_model_class(): void
    {
        TraitHostedUser::create(['name' => 'A', 'email' => 'a@example.com'])->authoredPosts()->getRelated();

        $userMemo = (new ReflectionProperty(User::class, 'relationNameMemo'))->getValue();
        $this->assertIsArray($userMemo);
        $this->assertSame([], $userMemo, 'TraitHostedUser memo entries must not leak into User (trait statics are per-class)');
    }
}
