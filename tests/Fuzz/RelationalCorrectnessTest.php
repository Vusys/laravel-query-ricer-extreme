<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Fuzz;

use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Tests\Fuzz\Support\ConnectionContext;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\Post;
use Vusys\QueryRicerExtreme\Tests\Models\Tag;
use Vusys\QueryRicerExtreme\Tests\Models\User;

/**
 * Oracle: all identity-map results must exactly match a fresh database read
 * on an isolated secondary connection with the package disabled.
 *
 * Exercises: keyset reads, relation traversal, and write→read consistency
 * across every supported DB engine.
 */
#[Group('fuzzer')]
final class RelationalCorrectnessTest extends DualDatabaseTestCase
{
    // -------------------------------------------------------------------------
    // Test 1 — keyset reads: cached vs fresh DB
    // -------------------------------------------------------------------------

    public function test_keyset_reads_match_oracle(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $graph = $this->seedGraph();
            $userIds = array_column($graph['users'], 'id');

            // Warm a random prefix so the map has partial hits
            $warmCount = mt_rand(0, count($userIds));
            foreach (array_slice($userIds, 0, $warmCount) as $id) {
                User::find($id);
            }

            $actual = $this->snapshotUsers(User::whereKey($userIds)->get());
            $oracle = IdentityMap::disabled(fn (): array => ConnectionContext::using('test_b', fn (): array => $this->snapshotUsers(User::whereKey($userIds)->get())));

            $this->assertSame($oracle, $actual, "keyset reads [seed={$seed} step={$step}]");
        });
    }

    // -------------------------------------------------------------------------
    // Test 2 — relation traversal: user→posts→tag, user→comments
    // -------------------------------------------------------------------------

    public function test_relation_traversal_matches_oracle(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $graph = $this->seedGraph();
            $userIds = array_column($graph['users'], 'id');

            // Warm all users on test_a so relations may be served from memory
            foreach ($userIds as $id) {
                User::find($id);
            }

            $actual = $this->snapshotUsersWithRelations($userIds);
            $oracle = IdentityMap::disabled(fn (): array => ConnectionContext::using('test_b', fn (): array => $this->snapshotUsersWithRelations($userIds)));

            $this->assertSame($oracle, $actual, "relation traversal [seed={$seed} step={$step}]");
        });
    }

    // -------------------------------------------------------------------------
    // Test 3 — mutation→read consistency: package must evict/update on write
    // -------------------------------------------------------------------------

    public function test_mutation_read_consistency_matches_oracle(): void
    {
        $this->eachSeed(function (int $seed, int $step): void {
            IdentityMap::flush();

            $graph = $this->seedGraph();
            $userIds = array_column($graph['users'], 'id');
            $tagIds = array_column($graph['tags'], 'id');
            $postIds = array_column($graph['posts'], 'id');
            $commentIds = array_column($graph['comments'], 'id');

            // Warm everything so every entry is cached before mutations
            foreach ($userIds as $id) {
                User::find($id);
            }
            foreach ($postIds as $id) {
                Post::find($id);
            }
            foreach ($tagIds as $id) {
                Tag::find($id);
            }
            foreach ($commentIds as $id) {
                Comment::find($id);
            }

            // Build a deterministic mutation plan (no side-effects)
            $mutations = $this->buildMutationPlan($userIds, $tagIds, $postIds, $commentIds);

            // Apply on test_a with identity map active
            foreach ($mutations as $mutation) {
                $this->applyMutation($mutation);
            }

            // Apply the same mutations on test_b with identity map disabled
            IdentityMap::disabled(function () use ($mutations): void {
                ConnectionContext::using('test_b', function () use ($mutations): void {
                    foreach ($mutations as $mutation) {
                        $this->applyMutation($mutation);
                    }
                });
            });

            // Compare: both sides should agree on the full graph state
            $actual = $this->snapshotAll($graph);
            $oracle = IdentityMap::disabled(fn (): array => ConnectionContext::using('test_b', fn (): array => $this->snapshotAll($graph)));

            $this->assertSame($oracle, $actual, "mutation consistency [seed={$seed} step={$step}]");
        });
    }

    // -------------------------------------------------------------------------
    // Seeding helpers
    // -------------------------------------------------------------------------

    /**
     * Seed a relational graph on the primary connection and mirror it to test_b.
     *
     * @return array{
     *     users: list<array{id: int, name: string, email: string, active: bool, score: int|null, bio: string|null}>,
     *     tags: list<array{id: int, name: string, priority: int, color: string|null}>,
     *     posts: list<array{id: int, user_id: int, tag_id: int|null, title: string, published: bool, view_count: int, rating: float|null}>,
     *     comments: list<array{id: int, commentable_type: string, commentable_id: int, body: string, likes: int}>
     * }
     */
    private function seedGraph(): array
    {
        $users = [];
        for ($i = 0, $count = mt_rand(2, 5); $i < $count; $i++) {
            $user = User::factory()->create(['active' => (bool) mt_rand(0, 1)]);
            $users[] = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'active' => $user->active, 'score' => $user->score, 'bio' => $user->bio];
        }

        $tags = [];
        for ($i = 0, $count = mt_rand(1, 3); $i < $count; $i++) {
            $tag = Tag::factory()->create();
            $tags[] = ['id' => $tag->id, 'name' => $tag->name, 'priority' => $tag->priority, 'color' => $tag->color];
        }

        $posts = [];
        for ($i = 0, $count = mt_rand(1, 4); $i < $count; $i++) {
            $userId = $users[mt_rand(0, count($users) - 1)]['id'];
            $tagId = mt_rand(0, 1) === 1 ? $tags[mt_rand(0, count($tags) - 1)]['id'] : null;
            $post = Post::factory()->create([
                'user_id' => $userId,
                'tag_id' => $tagId,
                'published' => (bool) mt_rand(0, 1),
            ]);
            $posts[] = ['id' => $post->id, 'user_id' => $post->user_id, 'tag_id' => $post->tag_id, 'title' => $post->title, 'published' => $post->published, 'view_count' => $post->view_count, 'rating' => $post->rating];
        }

        $comments = [];
        foreach ($users as $u) {
            if (mt_rand(0, 1) === 1) {
                $c = Comment::create(['commentable_type' => User::class, 'commentable_id' => $u['id'], 'body' => "comment-u{$u['id']}", 'likes' => mt_rand(0, 99)]);
                $comments[] = ['id' => $c->id, 'commentable_type' => User::class, 'commentable_id' => $u['id'], 'body' => $c->body, 'likes' => $c->likes];
            }
        }
        foreach ($posts as $p) {
            if (mt_rand(0, 1) === 1) {
                $c = Comment::create(['commentable_type' => Post::class, 'commentable_id' => $p['id'], 'body' => "comment-p{$p['id']}", 'likes' => mt_rand(0, 99)]);
                $comments[] = ['id' => $c->id, 'commentable_type' => Post::class, 'commentable_id' => $p['id'], 'body' => $c->body, 'likes' => $c->likes];
            }
        }

        $this->mirrorToSecondary($users, $tags, $posts, $comments);

        return ['users' => $users, 'tags' => $tags, 'posts' => $posts, 'comments' => $comments];
    }

    /**
     * @param  list<array{id: int, name: string, email: string, active: bool, score: int|null, bio: string|null}>  $users
     * @param  list<array{id: int, name: string, priority: int, color: string|null}>  $tags
     * @param  list<array{id: int, user_id: int, tag_id: int|null, title: string, published: bool, view_count: int, rating: float|null}>  $posts
     * @param  list<array{id: int, commentable_type: string, commentable_id: int, body: string, likes: int}>  $comments
     */
    private function mirrorToSecondary(array $users, array $tags, array $posts, array $comments): void
    {
        IdentityMap::disabled(function () use ($users, $tags, $posts, $comments): void {
            ConnectionContext::using('test_b', function () use ($users, $tags, $posts, $comments): void {
                foreach ($users as $u) {
                    User::forceCreate(['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'active' => $u['active'], 'score' => $u['score'], 'bio' => $u['bio']]);
                }
                foreach ($tags as $t) {
                    Tag::forceCreate(['id' => $t['id'], 'name' => $t['name'], 'priority' => $t['priority'], 'color' => $t['color']]);
                }
                foreach ($posts as $p) {
                    Post::forceCreate(['id' => $p['id'], 'user_id' => $p['user_id'], 'tag_id' => $p['tag_id'], 'title' => $p['title'], 'published' => $p['published'], 'view_count' => $p['view_count'], 'rating' => $p['rating']]);
                }
                foreach ($comments as $c) {
                    Comment::forceCreate(['id' => $c['id'], 'commentable_type' => $c['commentable_type'], 'commentable_id' => $c['commentable_id'], 'body' => $c['body'], 'likes' => $c['likes']]);
                }
            });
        });
    }

    // -------------------------------------------------------------------------
    // Mutation helpers
    // -------------------------------------------------------------------------

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $tagIds
     * @param  list<int>  $postIds
     * @param  list<int>  $commentIds
     * @return list<array{model: string, id: int, op: string, str_val: string|null, int_val: int|null, float_val: float|null}>
     */
    private function buildMutationPlan(array $userIds, array $tagIds, array $postIds, array $commentIds): array
    {
        $plan = [];

        $userOps = ['noop', 'rename', 'toggle-active', 'soft-delete', 'soft-delete-restore', 'set-score', 'set-bio'];
        foreach ($userIds as $id) {
            $op = $userOps[mt_rand(0, 6)];
            $strVal = match ($op) {
                'rename' => "Renamed-{$id}-".mt_rand(1, 99),
                'set-bio' => mt_rand(0, 1) === 1 ? "Bio-{$id}-".mt_rand(1, 99) : null,
                default => null,
            };
            $intVal = $op === 'set-score' ? (mt_rand(0, 1) === 1 ? mt_rand(0, 100) : null) : null;
            $plan[] = ['model' => 'user', 'id' => $id, 'op' => $op, 'str_val' => $strVal, 'int_val' => $intVal, 'float_val' => null];
        }

        $tagOps = ['noop', 'rename', 'set-priority', 'set-color'];
        foreach ($tagIds as $id) {
            $op = $tagOps[mt_rand(0, 3)];
            $strVal = match ($op) {
                'rename' => "Tag-{$id}-".mt_rand(1, 99),
                'set-color' => mt_rand(0, 1) === 0 ? null : sprintf('#%06X', mt_rand(0, 0xFFFFFF)),
                default => null,
            };
            $intVal = $op === 'set-priority' ? mt_rand(0, 5) : null;
            $plan[] = ['model' => 'tag', 'id' => $id, 'op' => $op, 'str_val' => $strVal, 'int_val' => $intVal, 'float_val' => null];
        }

        $postOps = ['noop', 'rename-title', 'toggle-published', 'set-view-count', 'set-rating'];
        foreach ($postIds as $id) {
            $op = $postOps[mt_rand(0, 4)];
            $strVal = $op === 'rename-title' ? "Title-{$id}-".mt_rand(1, 99) : null;
            $intVal = $op === 'set-view-count' ? mt_rand(0, 9999) : null;
            $floatVal = $op === 'set-rating' ? (mt_rand(0, 1) === 0 ? null : (float) (mt_rand(2, 10) / 2)) : null;
            $plan[] = ['model' => 'post', 'id' => $id, 'op' => $op, 'str_val' => $strVal, 'int_val' => $intVal, 'float_val' => $floatVal];
        }

        $commentOps = ['noop', 'set-likes'];
        foreach ($commentIds as $id) {
            $op = $commentOps[mt_rand(0, 1)];
            $intVal = $op === 'set-likes' ? mt_rand(0, 999) : null;
            $plan[] = ['model' => 'comment', 'id' => $id, 'op' => $op, 'str_val' => null, 'int_val' => $intVal, 'float_val' => null];
        }

        return $plan;
    }

    /**
     * @param  array{model: string, id: int, op: string, str_val: string|null, int_val: int|null, float_val: float|null}  $mutation
     */
    private function applyMutation(array $mutation): void
    {
        match ($mutation['model']) {
            'user' => $this->applyUserMutation($mutation),
            'post' => $this->applyPostMutation($mutation),
            'tag' => $this->applyTagMutation($mutation),
            'comment' => $this->applyCommentMutation($mutation),
            default => throw new InvalidArgumentException("Unknown model: {$mutation['model']}"),
        };
    }

    /**
     * @param  array{model: string, id: int, op: string, str_val: string|null, int_val: int|null, float_val: float|null}  $mutation
     */
    private function applyUserMutation(array $mutation): void
    {
        $user = User::find($mutation['id']);
        if (! $user instanceof User) {
            return;
        }

        match ($mutation['op']) {
            'noop' => null,
            'rename' => (function () use ($user, $mutation): void {
                if (is_string($mutation['str_val'])) {
                    $user->name = $mutation['str_val'];
                    $user->save();
                }
            })(),
            'toggle-active' => (function () use ($user): void {
                $user->active = ! $user->active;
                $user->save();
            })(),
            'soft-delete' => $user->delete(),
            'soft-delete-restore' => (function () use ($user): void {
                $user->delete();
                $user->restore();
            })(),
            'set-score' => (function () use ($user, $mutation): void {
                $user->score = $mutation['int_val'];
                $user->save();
            })(),
            'set-bio' => (function () use ($user, $mutation): void {
                $user->bio = $mutation['str_val'];
                $user->save();
            })(),
            default => throw new InvalidArgumentException("Unknown user op: {$mutation['op']}"),
        };
    }

    /**
     * @param  array{model: string, id: int, op: string, str_val: string|null, int_val: int|null, float_val: float|null}  $mutation
     */
    private function applyTagMutation(array $mutation): void
    {
        $tag = Tag::find($mutation['id']);
        if (! $tag instanceof Tag) {
            return;
        }

        match ($mutation['op']) {
            'noop' => null,
            'rename' => (function () use ($tag, $mutation): void {
                if (is_string($mutation['str_val'])) {
                    $tag->name = $mutation['str_val'];
                    $tag->save();
                }
            })(),
            'set-priority' => (function () use ($tag, $mutation): void {
                if (is_int($mutation['int_val'])) {
                    $tag->priority = $mutation['int_val'];
                    $tag->save();
                }
            })(),
            'set-color' => (function () use ($tag, $mutation): void {
                $tag->color = $mutation['str_val'];
                $tag->save();
            })(),
            default => throw new InvalidArgumentException("Unknown tag op: {$mutation['op']}"),
        };
    }

    /**
     * @param  array{model: string, id: int, op: string, str_val: string|null, int_val: int|null, float_val: float|null}  $mutation
     */
    private function applyPostMutation(array $mutation): void
    {
        $post = Post::find($mutation['id']);
        if (! $post instanceof Post) {
            return;
        }

        match ($mutation['op']) {
            'noop' => null,
            'rename-title' => (function () use ($post, $mutation): void {
                if (is_string($mutation['str_val'])) {
                    $post->title = $mutation['str_val'];
                    $post->save();
                }
            })(),
            'toggle-published' => (function () use ($post): void {
                $post->published = ! $post->published;
                $post->save();
            })(),
            'set-view-count' => (function () use ($post, $mutation): void {
                if (is_int($mutation['int_val'])) {
                    $post->view_count = $mutation['int_val'];
                    $post->save();
                }
            })(),
            'set-rating' => (function () use ($post, $mutation): void {
                $post->rating = $mutation['float_val'];
                $post->save();
            })(),
            default => throw new InvalidArgumentException("Unknown post op: {$mutation['op']}"),
        };
    }

    /**
     * @param  array{model: string, id: int, op: string, str_val: string|null, int_val: int|null, float_val: float|null}  $mutation
     */
    private function applyCommentMutation(array $mutation): void
    {
        $comment = Comment::find($mutation['id']);
        if (! $comment instanceof Comment) {
            return;
        }

        match ($mutation['op']) {
            'noop' => null,
            'set-likes' => (function () use ($comment, $mutation): void {
                if (is_int($mutation['int_val'])) {
                    $comment->likes = $mutation['int_val'];
                    $comment->save();
                }
            })(),
            default => throw new InvalidArgumentException("Unknown comment op: {$mutation['op']}"),
        };
    }

    // -------------------------------------------------------------------------
    // Snapshot helpers
    // -------------------------------------------------------------------------

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{id: int, name: string, email: string, active: bool, score: int|null, bio: string|null, deleted_at: string|null}>
     */
    private function snapshotUsers(Collection $users): array
    {
        return array_values(
            $users->sortBy('id')
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'active' => $u->active,
                    'score' => $u->score,
                    'bio' => $u->bio,
                    'deleted_at' => $u->deleted_at === null ? null : '<deleted>',
                ])
                ->all()
        );
    }

    /**
     * Snapshot user→posts→tag (including new tag fields) and user→comments.
     *
     * @param  list<int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    private function snapshotUsersWithRelations(array $userIds): array
    {
        return array_values(
            User::whereKey($userIds)->get()
                ->sortBy('id')
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'active' => $u->active,
                    'score' => $u->score,
                    'bio' => $u->bio,
                    'posts' => array_values($u->posts->sortBy('id')->map(fn (Post $p): array => [
                        'id' => $p->id,
                        'user_id' => $p->user_id,
                        'tag_id' => $p->tag_id,
                        'title' => $p->title,
                        'published' => $p->published,
                        'view_count' => $p->view_count,
                        'rating' => $p->rating,
                        'tag_name' => $p->tag?->name,
                        'tag_priority' => $p->tag?->priority,
                        'tag_color' => $p->tag?->color,
                    ])->all()),
                    'comments' => array_values($u->comments->sortBy('id')->map(fn (Comment $c): array => [
                        'id' => $c->id,
                        'body' => $c->body,
                        'likes' => $c->likes,
                    ])->all()),
                ])
                ->all()
        );
    }

    /**
     * Full-graph snapshot for all four model types (used by the mutation consistency test).
     *
     * @param  array{
     *     users: list<array{id: int, name: string, email: string, active: bool, score: int|null, bio: string|null}>,
     *     tags: list<array{id: int, name: string, priority: int, color: string|null}>,
     *     posts: list<array{id: int, user_id: int, tag_id: int|null, title: string, published: bool, view_count: int, rating: float|null}>,
     *     comments: list<array{id: int, commentable_type: string, commentable_id: int, body: string, likes: int}>
     * }  $graph
     * @return array<string, mixed>
     */
    private function snapshotAll(array $graph): array
    {
        $userIds = array_column($graph['users'], 'id');
        $tagIds = array_column($graph['tags'], 'id');
        $postIds = array_column($graph['posts'], 'id');
        $commentIds = array_column($graph['comments'], 'id');

        return [
            'users' => $this->snapshotUsers(User::whereKey($userIds)->get()),
            'tags' => array_values(
                Tag::whereKey($tagIds)->get()
                    ->sortBy('id')
                    ->map(fn (Tag $t): array => [
                        'id' => $t->id,
                        'name' => $t->name,
                        'priority' => $t->priority,
                        'color' => $t->color,
                    ])
                    ->all()
            ),
            'posts' => array_values(
                Post::whereKey($postIds)->get()
                    ->sortBy('id')
                    ->map(fn (Post $p): array => [
                        'id' => $p->id,
                        'title' => $p->title,
                        'published' => $p->published,
                        'view_count' => $p->view_count,
                        'rating' => $p->rating,
                    ])
                    ->all()
            ),
            'comments' => array_values(
                Comment::whereKey($commentIds)->get()
                    ->sortBy('id')
                    ->map(fn (Comment $c): array => [
                        'id' => $c->id,
                        'body' => $c->body,
                        'likes' => $c->likes,
                    ])
                    ->all()
            ),
        ];
    }
}
