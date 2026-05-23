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
        Schema::dropIfExists('uuid_users');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
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

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('title');
            $table->boolean('published')->default(false);
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('commentable');
            $table->string('body');
            $table->timestamps();
        });
    }

    #[\Override]
    protected function tearDown(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('uuid_users');
        Schema::dropIfExists('users');
        DB::disconnect();
        parent::tearDown();
    }
}
