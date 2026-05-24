<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Vusys\QueryRicerExtreme\QueryRicerExtremeServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    #[\Override]
    protected function getPackageProviders($app): array
    {
        return [QueryRicerExtremeServiceProvider::class];
    }

    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('uuid_users');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->integer('score')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('uuid_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->smallInteger('priority')->default(0);
            $table->string('color', 7)->nullable();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('tag_id')->nullable()->constrained();
            $table->foreignId('label_id')->nullable()->constrained();
            $table->string('title');
            $table->boolean('published')->default(false);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->decimal('rating', 4, 1)->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('commentable');
            $table->string('body');
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();
        });
    }

    #[\Override]
    protected function tearDown(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('uuid_users');
        Schema::dropIfExists('users');
        DB::disconnect();
        parent::tearDown();
    }
}
