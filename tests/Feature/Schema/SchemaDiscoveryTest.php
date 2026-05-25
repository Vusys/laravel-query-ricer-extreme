<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class SchemaDiscoveryTest extends TestCase
{
    private SchemaDiscovery $discovery;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = resolve(SchemaDiscovery::class);
        $this->discovery->flush();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Schema::dropIfExists('m15_compound');
        Schema::dropIfExists('m15_nonunique');
        Schema::dropIfExists('m15_cache_probe');
        Schema::dropIfExists('m15_pk_only');
        Schema::dropIfExists('m15_mixed');
        Schema::dropIfExists('m15_interleaved');
        parent::tearDown();
    }

    #[Test]
    public function unique_index_is_discovered(): void
    {
        $this->assertSame([['email']], $this->discovery->uniqueIndexesFor(User::class));
    }

    #[Test]
    public function primary_key_is_excluded(): void
    {
        Schema::create('m15_pk_only', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        try {
            $model = new class extends Model
            {
                protected $table = 'm15_pk_only';

                public $timestamps = false;
            };

            $this->assertSame([], $this->discovery->uniqueIndexesFor($model::class), 'A table with only a primary key has no discoverable unique indexes');
        } finally {
            Schema::dropIfExists('m15_pk_only');
        }
    }

    #[Test]
    public function non_unique_index_is_excluded_when_mixed_with_unique(): void
    {
        Schema::create('m15_mixed', function (Blueprint $table): void {
            $table->id();
            $table->string('searchable')->index();
            $table->string('handle')->unique();
        });

        try {
            $model = new class extends Model
            {
                protected $table = 'm15_mixed';

                public $timestamps = false;
            };

            $this->assertSame(
                [['handle']],
                $this->discovery->uniqueIndexesFor($model::class),
                'A non-unique index appearing alongside a unique index must not stop discovery from reporting the unique one',
            );
        } finally {
            Schema::dropIfExists('m15_mixed');
        }
    }

    #[Test]
    public function non_unique_index_does_not_short_circuit_subsequent_unique_indexes(): void
    {
        // Two unique indexes separated by a non-unique one. Index iteration order
        // is DB-specific, so regardless of which direction the driver walks the
        // list, the non-unique entry will appear between the two uniques — the
        // loop must continue past it, not break out.
        Schema::create('m15_interleaved', function (Blueprint $table): void {
            $table->id();
            $table->string('alpha')->unique();
            $table->string('beta')->index();
            $table->string('gamma')->unique();
        });

        try {
            $model = new class extends Model
            {
                protected $table = 'm15_interleaved';

                public $timestamps = false;
            };

            $result = $this->discovery->uniqueIndexesFor($model::class);
            $sorted = array_map(fn (array $cols): array => $cols, $result);
            sort($sorted);

            $this->assertSame(
                [['alpha'], ['gamma']],
                $sorted,
                'Both unique indexes must be discovered even when a non-unique index sits between them in iteration order',
            );
        } finally {
            Schema::dropIfExists('m15_interleaved');
        }
    }

    #[Test]
    public function compound_unique_index_is_discovered(): void
    {
        Schema::create('m15_compound', function (Blueprint $table): void {
            $table->id();
            $table->string('a');
            $table->string('b');
            $table->string('c');
            $table->unique(['a', 'b']);
        });

        $model = new class extends Model
        {
            protected $table = 'm15_compound';

            public $timestamps = false;
        };

        $this->assertSame([['a', 'b']], $this->discovery->uniqueIndexesFor($model::class));
    }

    #[Test]
    public function non_unique_index_is_excluded(): void
    {
        Schema::create('m15_nonunique', function (Blueprint $table): void {
            $table->id();
            $table->string('foo')->index();
            $table->string('bar');
        });

        $model = new class extends Model
        {
            protected $table = 'm15_nonunique';

            public $timestamps = false;
        };

        $this->assertSame([], $this->discovery->uniqueIndexesFor($model::class));
    }

    #[Test]
    public function nonexistent_table_returns_empty(): void
    {
        $model = new class extends Model
        {
            protected $table = 'm15_does_not_exist';

            public $timestamps = false;
        };

        $this->assertSame([], $this->discovery->uniqueIndexesFor($model::class));
    }

    #[Test]
    public function results_are_cached(): void
    {
        Schema::create('m15_cache_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
        });

        $model = new class extends Model
        {
            protected $table = 'm15_cache_probe';

            public $timestamps = false;
        };

        $first = $this->discovery->uniqueIndexesFor($model::class);
        $this->assertSame([['slug']], $first);

        Schema::dropIfExists('m15_cache_probe');

        $cached = $this->discovery->uniqueIndexesFor($model::class);
        $this->assertSame([['slug']], $cached, 'Cached result must be returned after underlying table is dropped');
    }

    #[Test]
    public function flush_clears_cache(): void
    {
        Schema::create('m15_cache_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
        });

        $model = new class extends Model
        {
            protected $table = 'm15_cache_probe';

            public $timestamps = false;
        };

        $this->discovery->uniqueIndexesFor($model::class);
        Schema::dropIfExists('m15_cache_probe');
        $this->discovery->flush();

        $afterFlush = $this->discovery->uniqueIndexesFor($model::class);
        $this->assertSame([], $afterFlush, 'After flush, next call must re-introspect schema');
    }

    #[Test]
    public function disabled_via_config_returns_empty(): void
    {
        config(['query-ricer-extreme.schema_discovery.enabled' => false]);

        $this->assertSame([], $this->discovery->uniqueIndexesFor(User::class));
    }

    #[Test]
    public function introspection_does_not_pollute_db_query_log(): void
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $this->discovery->uniqueIndexesFor(User::class);
        $this->discovery->for(new User, 'email');

        $this->assertSame([], DB::connection()->getQueryLog(), 'Schema introspection PRAGMA / information_schema queries must not appear in the user-facing query log');
    }
}
