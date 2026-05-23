<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\Comment;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class MorphToMemoryTest extends TestCase
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
    public function morph_to_returns_related_from_memory(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertSame(0, $queryCount, 'morphTo should hit memory without SQL');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->getKey());
    }

    #[Test]
    public function morph_to_returns_same_instance_as_cached(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $result = $comment->commentable;

        $this->assertSame($user, $result);
    }

    #[Test]
    public function morph_to_falls_back_to_sql_when_related_not_in_store(): void
    {
        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        $comment = Comment::create([
            'commentable_type' => User::class,
            'commentable_id' => $user->id,
            'body' => 'hello',
        ]);

        $this->store->flush();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = $comment->commentable;

        $this->assertGreaterThan(0, $queryCount, 'morphTo should issue SQL when not in memory');
        $this->assertNotNull($result);
        $this->assertSame($user->id, $result->getKey());
    }
}
