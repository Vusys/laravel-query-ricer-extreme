<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Fuzz;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class DualDatabaseTestCase extends FuzzerTestCase
{
    private const string SECONDARY = 'lqre_test_b';

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSecondaryConnection();
        $this->createSecondaryDatabaseIfNeeded();
        $this->migrateSecondaryDatabase();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->dropSecondaryTables();
        DB::connection('test_b')->disconnect();
        $this->dropSecondaryDatabaseIfNeeded();
        parent::tearDown();
    }

    private function configureSecondaryConnection(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            config(['database.connections.test_b' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => true,
            ]]);

            return;
        }

        $defaultName = config('database.default');
        if (! is_string($defaultName)) {
            return;
        }

        $primary = config("database.connections.{$defaultName}");
        if (! is_array($primary)) {
            return;
        }

        config(['database.connections.test_b' => array_merge($primary, ['database' => self::SECONDARY])]);
    }

    private function createSecondaryDatabaseIfNeeded(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => null,
            'mysql', 'mariadb' => DB::statement('CREATE DATABASE IF NOT EXISTS `'.self::SECONDARY.'`'),
            'pgsql' => $this->createPostgresDatabase(),
            default => null,
        };
    }

    private function createPostgresDatabase(): void
    {
        try {
            DB::statement('CREATE DATABASE "'.self::SECONDARY.'"');
        } catch (QueryException $e) {
            // 42P04 = duplicate_database; suppress only this, surface everything else
            if (($e->errorInfo[0] ?? null) !== '42P04') {
                throw $e;
            }
        }
    }

    private function migrateSecondaryDatabase(): void
    {
        Schema::connection('test_b')->dropIfExists('post_tag');
        Schema::connection('test_b')->dropIfExists('comments');
        Schema::connection('test_b')->dropIfExists('posts');
        Schema::connection('test_b')->dropIfExists('tags');
        Schema::connection('test_b')->dropIfExists('users');

        Schema::connection('test_b')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->boolean('active')->default(true);
            $table->integer('score')->nullable();
            $table->text('bio')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::connection('test_b')->create('tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->smallInteger('priority')->default(0);
            $table->string('color', 7)->nullable();
            $table->timestamps();
        });

        Schema::connection('test_b')->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tag_id')->nullable();
            $table->unsignedBigInteger('label_id')->nullable();
            $table->string('title');
            $table->boolean('published')->default(false);
            $table->unsignedBigInteger('view_count')->default(0);
            $table->decimal('rating', 4, 1)->nullable();
            $table->timestamps();
        });

        Schema::connection('test_b')->create('comments', function (Blueprint $table): void {
            $table->id();
            $table->morphs('commentable');
            $table->string('body');
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();
        });

        Schema::connection('test_b')->create('post_tag', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->unsignedBigInteger('tag_id');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->string('role')->nullable();
            $table->timestamps();
            $table->unique(['post_id', 'tag_id']);
        });
    }

    private function dropSecondaryTables(): void
    {
        Schema::connection('test_b')->dropIfExists('post_tag');
        Schema::connection('test_b')->dropIfExists('comments');
        Schema::connection('test_b')->dropIfExists('posts');
        Schema::connection('test_b')->dropIfExists('tags');
        Schema::connection('test_b')->dropIfExists('users');
    }

    private function dropSecondaryDatabaseIfNeeded(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => null,
            'mysql', 'mariadb' => DB::statement('DROP DATABASE IF EXISTS `'.self::SECONDARY.'`'),
            'pgsql' => null,
            default => null,
        };
    }
}
