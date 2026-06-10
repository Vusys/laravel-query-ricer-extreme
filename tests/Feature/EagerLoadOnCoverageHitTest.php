<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class EagerLoadOnCoverageHitTest extends TestCase
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
    public function with_first_applies_eager_load_on_coverage_hit(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->posts()->create(['title' => 'P', 'published' => true]);

        User::where('active', true)->get(); // record coverage

        $served = User::with('posts')->where('active', true)->first();

        $this->assertNotNull($served);
        $this->assertTrue($served->relationLoaded('posts'));
        $this->assertCount(1, $served->posts);
    }

    #[Test]
    public function with_sole_applies_eager_load_on_coverage_hit(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->posts()->create(['title' => 'P', 'published' => true]);

        User::where('active', true)->get(); // record coverage

        $served = User::with('posts')->where('active', true)->sole();

        $this->assertTrue($served->relationLoaded('posts'));
        $this->assertCount(1, $served->posts);
    }

    #[Test]
    public function with_first_applies_nested_eager_load_on_coverage_hit(): void
    {
        $user = User::factory()->create(['active' => true]);
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $post->tags()->attach(Tag::create(['name' => 'T', 'priority' => 1]), ['active' => true, 'priority' => 1]);

        User::where('active', true)->get(); // record coverage

        $served = User::with('posts.tags')->where('active', true)->first();

        $this->assertNotNull($served);
        $this->assertTrue($served->relationLoaded('posts'));
        $firstPost = $served->posts->first();
        $this->assertNotNull($firstPost);
        $this->assertTrue($firstPost->relationLoaded('tags'));
        $this->assertCount(1, $firstPost->tags);
    }

    #[Test]
    public function has_many_relation_with_eager_load_matches_oracle(): void
    {
        $user = User::factory()->create();
        $post = $user->posts()->create(['title' => 'P', 'published' => true]);
        $post->tags()->attach(Tag::create(['name' => 'T', 'priority' => 1]), ['active' => true, 'priority' => 1]);

        $user->load('posts'); // warm relation memory

        $served = $user->posts()->with('tags')->get();
        $first = $served->first();

        $this->assertNotNull($first);
        $this->assertTrue($first->relationLoaded('tags'));
        $this->assertCount(1, $first->tags);
    }

    #[Test]
    public function morph_many_relation_with_eager_load_matches_oracle(): void
    {
        $user = User::factory()->create();
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $user->id, 'body' => 'A']);

        $user->load('comments'); // warm relation memory

        $served = $user->comments()->with('commentable')->get();
        $first = $served->first();

        $this->assertNotNull($first);
        $this->assertTrue($first->relationLoaded('commentable'));
    }

    #[Test]
    public function belongs_to_many_relation_with_eager_load_matches_oracle(): void
    {
        $user = User::factory()->create();
        $post = Post::create(['user_id' => $user->id, 'title' => 'P', 'published' => true]);
        $tag = Tag::create(['name' => 'T', 'priority' => 1]);
        $post->tags()->attach($tag, ['active' => true, 'priority' => 1]);

        $post->load('tags'); // warm relation memory

        $served = $post->tags()->with('posts')->get();
        $first = $served->first();

        $this->assertNotNull($first);
        $this->assertTrue($first->relationLoaded('posts'));
    }

    #[Test]
    public function with_first_under_prevent_lazy_loading_does_not_throw(): void
    {
        $user = User::factory()->create(['active' => true]);
        $user->posts()->create(['title' => 'P', 'published' => true]);

        User::where('active', true)->get(); // record coverage

        Model::preventLazyLoading(true);

        try {
            $served = User::with('posts')->where('active', true)->first();

            $this->assertNotNull($served);
            $this->assertTrue($served->relationLoaded('posts'));
            // Accessing the relation must not raise LazyLoadingViolationException.
            $this->assertCount(1, $served->posts);
        } finally {
            Model::preventLazyLoading(false);
        }
    }
}
