<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ProcessTruthTest extends TestCase
{
    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
        config(['query-ricer-extreme.mode' => 'process_truth']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        config(['query-ricer-extreme.mode' => 'default']);
        parent::tearDown();
    }

    private function createFresh(string $name, string $email, bool $active = true): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'active' => $active]);
        $this->store->flush();

        return $user;
    }

    // -----------------------------------------------------------------------
    // Dirty predicate evaluation — bounded key-set path
    // -----------------------------------------------------------------------

    #[Test]
    public function dirty_model_rejected_when_predicate_no_longer_matches(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        User::find($bob->id);

        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Both models known; no SQL expected even in process-truth mode');
        $this->assertCount(0, $result, 'Alice is dirty-false, Bob is false — no match');
    }

    #[Test]
    public function dirty_model_matched_when_predicate_matches_current_value(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: false);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = true;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Model known; predicate matches dirty value — no SQL');
        $this->assertCount(1, $result);
        $this->assertSame($alice->id, $result->first()?->id);
    }

    #[Test]
    public function both_dirty_true_no_sql_needed(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        User::find($alice->id);
        $bobLive = User::find($bob->id);
        $this->assertInstanceOf(User::class, $bobLive);
        $bobLive->active = true;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id, $bob->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Both dirty-true; no SQL needed');
        $this->assertCount(2, $result);
    }

    // -----------------------------------------------------------------------
    // default mode — dirty changes must NOT affect evaluation
    // -----------------------------------------------------------------------

    #[Test]
    public function default_mode_ignores_dirty_changes(): void
    {
        config(['query-ricer-extreme.mode' => 'default']);

        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Original value is true — no SQL needed');
        $this->assertCount(1, $result, 'default mode: original value used, dirty ignored');
    }

    // -----------------------------------------------------------------------
    // Save reconciliation
    // -----------------------------------------------------------------------

    #[Test]
    public function after_save_original_value_updated_predicate_reflects_new_state(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = false;
        $aliceLive->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', true)->get();

        $this->assertSame(0, $queryCount, 'All known — no SQL');
        $this->assertCount(0, $result, 'After save, original is false — query for active=true returns nothing');
    }

    #[Test]
    public function after_save_updated_value_matches_query(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->active = false;
        $aliceLive->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('active', false)->get();

        $this->assertSame(0, $queryCount, 'All known — no SQL');
        $this->assertCount(1, $result, 'After save, original is false — matches active=false query');
    }

    #[Test]
    public function save_reconciles_string_attribute(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->name = 'Alice Updated';
        $aliceLive->save();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->where('name', 'Alice Updated')->get();

        $this->assertSame(0, $queryCount);
        $this->assertCount(1, $result);
    }

    // -----------------------------------------------------------------------
    // Mutation-aware coverage filtering
    // -----------------------------------------------------------------------

    #[Test]
    public function dirty_mutation_excludes_model_from_coverage_result(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: true);

        $models = User::where('active', true)->get();
        $aliceLive = $models->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Coverage hit — no SQL');
        $this->assertCount(1, $result, 'Alice is dirty-false, only Bob matches');
        $this->assertSame($bob->id, $result->first()?->id);
    }

    #[Test]
    public function dirty_mutation_excludes_model_from_coverage_when_no_longer_matches(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: false);
        $bob = $this->createFresh('Bob', 'bob@example.com', active: false);

        $models = User::where('active', false)->get();
        $aliceLive = $models->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        $aliceLive->active = true;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('active', false)->get();

        $this->assertSame(0, $queryCount, 'Coverage hit — no SQL');
        $this->assertCount(1, $result, 'Alice is dirty-true so no longer matches active=false; only Bob');
        $this->assertSame($bob->id, $result->first()?->id);
    }

    #[Test]
    public function default_mode_ignores_dirty_in_coverage_filter(): void
    {
        config(['query-ricer-extreme.mode' => 'default']);

        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $this->createFresh('Bob', 'bob@example.com', active: true);

        $models = User::where('active', true)->get();
        $aliceLive = $models->firstWhere('id', $alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);

        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('active', true)->get();

        $this->assertSame(0, $queryCount, 'Coverage hit — no SQL');
        $this->assertCount(2, $result, 'default mode: dirty mutation ignored, both models returned');
    }

    // -----------------------------------------------------------------------
    // Unique-key path: stale unique-key invalidation under process-truth
    // -----------------------------------------------------------------------

    #[Test]
    public function unique_key_lookup_misses_when_column_dirty_in_process_truth(): void
    {
        config([
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);

        $alice = $this->createFresh('Alice', 'alice@example.com');

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->email = 'changed@example.com';

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->get();

        $this->assertSame(1, $queryCount, 'Unique key is stale in process-truth — must hit SQL');
        $this->assertCount(1, $result, 'DB still has old email; SQL returns it');
    }

    #[Test]
    public function unique_key_lookup_still_hits_when_column_unchanged(): void
    {
        config([
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);

        $alice = $this->createFresh('Alice', 'alice@example.com');

        User::find($alice->id);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'alice@example.com')->get();

        // process-truth always bypasses the unique-key cache to avoid drift-in correctness issues
        $this->assertSame(1, $queryCount, 'process-truth always falls through for unique-key lookups');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function unique_key_lookup_bypasses_stale_absence_in_process_truth(): void
    {
        $this->setupStaleAbsenceScenario();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::where('email', 'new@example.com')->get();

        $this->assertSame(1, $queryCount, 'stale absence cache must be bypassed in process-truth');
        $this->assertCount(0, $result);
    }

    #[Test]
    public function exists_bypasses_stale_absence_in_process_truth(): void
    {
        $this->setupStaleAbsenceScenario();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $exists = User::where('email', 'new@example.com')->exists();

        $this->assertSame(1, $queryCount, 'stale absence cache must be bypassed in process-truth for exists()');
        $this->assertFalse($exists);
    }

    /** Configures unique-key index, creates Alice, primes the absence cache for 'new@example.com', then dirties Alice's email to that value (drift-in). */
    private function setupStaleAbsenceScenario(): void
    {
        config([
            'query-ricer-extreme.models' => [
                User::class => ['unique' => [['email']]],
            ],
        ]);

        $alice = $this->createFresh('Alice', 'alice@example.com');

        User::find($alice->id);

        User::where('email', 'new@example.com')->get();

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->email = 'new@example.com';
    }

    // -----------------------------------------------------------------------
    // Dirty whereIn evaluation
    // -----------------------------------------------------------------------

    #[Test]
    public function dirty_value_evaluated_in_wherein_predicate(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->name = 'Renamed';

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->whereIn('name', ['Renamed', 'Other'])->get();

        $this->assertSame(0, $queryCount, 'Dirty value matches whereIn — no SQL');
        $this->assertCount(1, $result);
    }

    #[Test]
    public function dirty_value_evaluated_in_where_not_in_predicate(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->name = 'Renamed';

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])->whereNotIn('name', ['Alice', 'Other'])->get();

        $this->assertSame(0, $queryCount, 'Dirty name not in original list — match via process-truth');
        $this->assertCount(1, $result);
    }

    // -----------------------------------------------------------------------
    // Relation filtering — process_truth must apply when filtering loaded
    // hasMany / morphMany / belongsToMany collections in memory.
    // -----------------------------------------------------------------------

    #[Test]
    public function has_many_in_memory_filter_respects_dirty_value(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $post1 = Post::create(['user_id' => $alice->id, 'title' => 'P1', 'published' => true]);
        Post::create(['user_id' => $alice->id, 'title' => 'P2', 'published' => true]);
        $this->store->flush();

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->load('posts');

        $dirtyPost = $aliceLive->posts->firstWhere('id', $post1->id);
        $this->assertInstanceOf(Post::class, $dirtyPost);
        $dirtyPost->published = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $published = $aliceLive->posts()->where('published', true)->get();

        $this->assertSame(0, $queryCount, 'hasMany filter must run in memory');
        $this->assertCount(1, $published, 'process_truth: dirty post excluded from published=true');
        $this->assertNotContains($post1->id, $published->pluck('id')->all());
    }

    #[Test]
    public function morph_many_in_memory_filter_respects_dirty_value(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $c1 = Comment::create(['commentable_type' => User::class, 'commentable_id' => $alice->id, 'body' => 'hi', 'likes' => 5]);
        Comment::create(['commentable_type' => User::class, 'commentable_id' => $alice->id, 'body' => 'bye', 'likes' => 10]);
        $this->store->flush();

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        $aliceLive->load('comments');

        $dirtyComment = $aliceLive->comments->firstWhere('id', $c1->id);
        $this->assertInstanceOf(Comment::class, $dirtyComment);
        $dirtyComment->likes = 100;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $popular = $aliceLive->comments()->where('likes', 100)->get();

        $this->assertSame(0, $queryCount, 'morphMany filter must run in memory');
        $this->assertCount(1, $popular, 'process_truth: dirty comment matched via current likes value');
        $this->assertSame($c1->id, $popular->first()?->id);
    }

    #[Test]
    public function where_has_belongs_to_inner_predicate_respects_dirty_parent(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com', active: true);
        $post = Post::create(['user_id' => $alice->id, 'title' => 'P', 'published' => true]);
        $this->store->flush();

        // Warm: Alice in store with active=true (original), Post in store.
        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        User::find($alice->id);
        Post::find($post->id);

        // Dirty: Alice.active flipped in memory only.
        $aliceLive->active = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = Post::whereKey([$post->id])
            ->whereHas('user', fn ($q) => $q->where('active', true))
            ->get();

        $this->assertSame(0, $queryCount, 'whereHas BelongsTo must run in memory');
        $this->assertCount(0, $result, 'process_truth: dirty parent excluded by whereHas inner predicate');
    }

    #[Test]
    public function where_has_has_many_inner_predicate_respects_dirty_child(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $post = Post::create(['user_id' => $alice->id, 'title' => 'P', 'published' => true]);
        $this->store->flush();

        $aliceLive = User::find($alice->id);
        $this->assertInstanceOf(User::class, $aliceLive);
        // Load posts so the graph has full coverage of Alice's children.
        $aliceLive->load('posts');

        $dirtyPost = $aliceLive->posts->firstWhere('id', $post->id);
        $this->assertInstanceOf(Post::class, $dirtyPost);
        $dirtyPost->published = false;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = User::whereKey([$alice->id])
            ->whereHas('posts', fn ($q) => $q->where('published', true))
            ->get();

        $this->assertSame(0, $queryCount, 'whereHas HasMany must run in memory');
        $this->assertCount(0, $result, 'process_truth: dirty child excluded by whereHas inner predicate');
    }

    #[Test]
    public function belongs_to_many_in_memory_filter_respects_dirty_related_value(): void
    {
        $alice = $this->createFresh('Alice', 'alice@example.com');
        $post = Post::create(['user_id' => $alice->id, 'title' => 'P', 'published' => true]);
        $tagA = Tag::create(['name' => 'red', 'priority' => 5]);
        $tagB = Tag::create(['name' => 'blue', 'priority' => 10]);
        $post->tags()->sync([$tagA->id, $tagB->id]);
        $this->store->flush();

        $postLive = Post::find($post->id);
        $this->assertInstanceOf(Post::class, $postLive);
        // Force the pivot graph + related entries to be loaded.
        $postLive->load('tags');

        $tagAEntry = Tag::find($tagA->id);
        $this->assertInstanceOf(Tag::class, $tagAEntry);
        $tagAEntry->priority = 99;

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $hits = $postLive->tags()->where('priority', 99)->get();

        $this->assertSame(0, $queryCount, 'belongsToMany filter must run in memory');
        $this->assertCount(1, $hits, 'process_truth: dirty tag matched via current priority value');
        $this->assertSame($tagA->id, $hits->first()?->id);
    }
}
