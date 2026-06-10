<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\IdentityMap;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\CastSample;
use Vusys\QueryRicerExtreme\Tests\Models\Enums\SampleStatus;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Predicate evaluation and unique-key lookups must match SQL (or bail to SQL)
 * across the cast types the existing models never exercised: datetime,
 * immutable_datetime, json/array, backed enum, decimal:2, and encrypted.
 * Anything not provably equivalent to the DB comparison must fall through.
 */
final class CastCoverageTest extends TestCase
{
    private IdentityMapStore $store;

    /** @var list<int> */
    private array $ids = [];

    #[\Override]
    protected function defineEnvironment($app): void
    {
        // Deterministic key so the `encrypted` cast has a working cipher.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.cipher', 'AES-256-CBC');
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    private function seedSamples(): void
    {
        $this->ids = [];
        $this->ids[] = CastSample::create([
            'name' => 'a',
            'happened_at' => Date::parse('2026-01-01 10:00:00'),
            'archived_at' => Date::parse('2026-02-01 08:00:00'),
            'payload' => ['tier' => 'gold', 'n' => 1],
            'status' => SampleStatus::Draft,
            'amount' => '10.00',
            'secret' => 'alpha-secret',
        ])->id;
        $this->ids[] = CastSample::create([
            'name' => 'b',
            'happened_at' => Date::parse('2026-03-15 12:30:00'),
            'archived_at' => null,
            'payload' => ['tier' => 'silver', 'n' => 2],
            'status' => SampleStatus::Published,
            'amount' => '19.99',
            'secret' => 'beta-secret',
        ])->id;
        $this->ids[] = CastSample::create([
            'name' => 'c',
            'happened_at' => Date::parse('2026-06-30 23:59:59'),
            'archived_at' => Date::parse('2026-07-01 00:00:00'),
            'payload' => ['tier' => 'gold', 'n' => 3],
            'status' => SampleStatus::Published,
            'amount' => '100.50',
            'secret' => 'gamma-secret',
        ])->id;
    }

    private function warm(): void
    {
        // Warm every row through find() (a full load) so memory could serve a
        // subsequent read. Ids come from seeding, so the warm records no coverage.
        foreach ($this->ids as $id) {
            CastSample::find($id);
        }
    }

    /**
     * Runs the same query with the map warm and with the map disabled; the
     * memory-served result must be identical to SQL (regardless of whether the
     * decision was to serve from memory or bail).
     *
     * @param  \Closure(): Builder<CastSample>  $build
     */
    private function assertMatchesOracle(\Closure $build, string $message): void
    {
        $ricer = $build()->orderBy('id')->pluck('id')->all();
        $oracle = IdentityMap::disabled(fn (): array => $build()->orderBy('id')->pluck('id')->all());

        $this->assertSame($oracle, $ricer, $message);
    }

    #[Test]
    public function datetime_predicates_match_oracle(): void
    {
        $this->seedSamples();
        $this->warm();

        $this->assertMatchesOracle(
            fn () => CastSample::where('happened_at', '>', Date::parse('2026-02-01 00:00:00')),
            'datetime > comparison',
        );
        $this->assertMatchesOracle(
            fn () => CastSample::where('happened_at', Date::parse('2026-01-01 10:00:00')),
            'datetime = comparison',
        );
    }

    #[Test]
    public function immutable_datetime_predicates_match_oracle(): void
    {
        $this->seedSamples();
        $this->warm();

        $this->assertMatchesOracle(
            fn () => CastSample::whereNull('archived_at'),
            'immutable_datetime IS NULL',
        );
        $this->assertMatchesOracle(
            fn () => CastSample::where('archived_at', '>=', Date::parse('2026-07-01 00:00:00')),
            'immutable_datetime >= comparison',
        );
    }

    #[Test]
    public function enum_predicates_match_oracle(): void
    {
        $this->seedSamples();
        $this->warm();

        $this->assertMatchesOracle(
            fn () => CastSample::where('status', SampleStatus::Published),
            'enum equality',
        );
        $this->assertMatchesOracle(
            fn () => CastSample::whereIn('status', [SampleStatus::Draft, SampleStatus::Published]),
            'enum whereIn',
        );
        // Raw backing value spelling must agree with the enum spelling.
        $this->assertMatchesOracle(
            fn () => CastSample::where('status', 'published'),
            'enum equality via raw backing value',
        );
    }

    #[Test]
    public function decimal_predicates_match_oracle(): void
    {
        $this->seedSamples();
        $this->warm();

        $this->assertMatchesOracle(
            fn () => CastSample::where('amount', '>', 15),
            'decimal > comparison',
        );
        $this->assertMatchesOracle(
            fn () => CastSample::where('amount', '19.99'),
            'decimal equality via string',
        );
        $this->assertMatchesOracle(
            fn () => CastSample::whereBetween('amount', [10, 50]),
            'decimal whereBetween',
        );
    }

    #[Test]
    public function json_predicates_match_oracle(): void
    {
        $this->seedSamples();
        $this->warm();

        // Whole-column equality over a json/array cast — the raw stored value is
        // a JSON string; the package must not coerce the array predicate.
        $this->assertMatchesOracle(
            fn () => CastSample::where('payload->tier', 'gold'),
            'json path equality',
        );
    }

    /**
     * Encrypted columns store per-row ciphertext that differs even for identical
     * plaintext, so an equality on the plaintext must never elide from memory on
     * the stored (encrypted) value — it must match SQL (which compares ciphertext).
     */
    #[Test]
    public function encrypted_predicate_matches_oracle_and_never_false_matches(): void
    {
        $this->seedSamples();
        $this->warm();

        $this->assertMatchesOracle(
            fn () => CastSample::where('secret', 'beta-secret'),
            'encrypted equality on plaintext must match SQL (empty: ciphertext != plaintext)',
        );

        // SQL compares the stored ciphertext against the plaintext literal, which
        // never matches — the memory path must agree (return nothing).
        $this->assertSame([], CastSample::where('secret', 'beta-secret')->pluck('id')->all());
    }

    #[Test]
    public function unique_key_lookup_on_string_cast_column_serves_from_memory(): void
    {
        config(['query-ricer-extreme.models' => [
            CastSample::class => ['unique' => [['name']]],
        ]]);

        $this->seedSamples();
        $this->warm();

        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });

        $found = CastSample::where('name', 'b')->first();

        $this->assertNotNull($found);
        $this->assertSame('b', $found->name);
        $this->assertSame(0, $queryCount, 'plain string unique-key lookup should serve from memory');
    }

    #[Test]
    public function unique_key_lookup_on_enum_cast_column_matches_oracle(): void
    {
        config(['query-ricer-extreme.models' => [
            CastSample::class => ['unique' => [['status']]],
        ]]);

        $this->seedSamples();
        $this->warm();

        // Whether this elides or bails, the result must equal SQL.
        $this->assertMatchesOracle(
            fn () => CastSample::where('status', SampleStatus::Draft),
            'enum-backed unique-key lookup',
        );
    }
}
