<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Explanation;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Label;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class BelongsToMemoryTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    #[Test]
    public function belongs_to_returns_related_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user;

        $this->assertSame(0, $queryCount, 'belongsTo should hit memory without SQL');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_falls_back_to_sql_when_related_not_in_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user;

        $this->assertGreaterThan(0, $queryCount, 'belongsTo should issue SQL when not in memory');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }

    #[Test]
    public function belongs_to_returns_same_instance_as_cached(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $result = $post->user;

        $this->assertSame($user, $result);
    }

    #[Test]
    public function belongs_to_falls_back_when_store_is_disabled(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $this->store->disabled(fn () => $post->user);

        $this->assertGreaterThan(0, $queryCount, 'belongsTo should issue SQL when store disabled');
        $this->assertNotNull($result);
    }

    #[Test]
    public function belongs_to_returns_null_when_fk_is_null(): void
    {
        $post = new Post(['title' => 'Draft', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->user;

        $this->assertSame(0, $queryCount, 'belongsTo should not issue SQL when FK is null');
        $this->assertNull($result);
    }

    #[Test]
    public function belongs_to_falls_back_when_related_has_no_identity_map(): void
    {
        $label = Label::create(['name' => 'php']);
        $post = Post::create(['user_id' => User::create(['name' => 'Alice', 'email' => 'a@example.com'])->id, 'label_id' => $label->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->label;

        $this->assertGreaterThan(0, $queryCount, 'belongsTo should issue SQL when related model has no HasIdentityMap');
        $this->assertNotNull($result);
        $this->assertInstanceOf(Label::class, $result);
    }

    #[Test]
    public function belongs_to_falls_back_when_related_without_trait_is_in_store(): void
    {
        // Label lacks HasIdentityMap. Manually putting it in the store must not cause the
        // belongsTo to serve it from memory — the !in_array(HasIdentityMap) guard must fire.
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $label = Label::create(['name' => 'php']);
        $post = Post::create(['user_id' => $user->id, 'label_id' => $label->id, 'title' => 'Hello', 'published' => false]);

        $this->store->remember($label);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $post->label;

        $this->assertGreaterThan(0, $queryCount, 'belongsTo must fall back to SQL when related model lacks HasIdentityMap, even if the entry is in the store');
        $this->assertInstanceOf(Label::class, $result);
    }

    #[Test]
    public function belongs_to_falls_back_when_query_has_join(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $relation = $post->user();
        $relation->getQuery()->join('tags', 'tags.id', '=', 'users.id');

        $explanations = $this->store->explain(fn () => $relation->getResults());

        $planTypes = array_map(fn (Explanation $e) => $e->type->value, $explanations);
        $this->assertNotContains(
            'return_belongs_to_from_memory',
            $planTypes,
            'queryHasHazards() must prevent MemoryBelongsTo from serving directly when a join is present',
        );
    }

    #[Test]
    public function belongs_to_falls_back_when_extra_where_constraint_present(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $post = Post::create(['user_id' => $user->id, 'title' => 'Hello', 'published' => false]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        // An extra where beyond the FK constraint means hasOnlyBaseConstraints() returns
        // false, which must trigger a SQL fallback.
        $result = $post->user()->where('name', 'Alice')->getResults();

        $this->assertGreaterThan(0, $queryCount, 'belongsTo with an extra where must fall back to SQL');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->id);
    }
}
