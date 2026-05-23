<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\DataProviders;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Concerns\ProvidesCartesian;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

#[Group('comprehensive')]
final class ConfigPermutationTest extends TestCase
{
    use ProvidesCartesian;

    private IdentityMapStore $store;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->store = resolve(IdentityMapStore::class);
        $this->store->flush();
    }

    // -------------------------------------------------------------------------
    // PK absence always works regardless of uk_tracking toggle
    // -------------------------------------------------------------------------

    /** @return array<string, array{bool}> */
    public static function ukTrackingProvider(): array
    {
        return [
            'uk_tracking=on' => [true],
            'uk_tracking=off' => [false],
        ];
    }

    #[DataProvider('ukTrackingProvider')]
    public function test_primary_key_absence_always_prevents_second_sql(bool $ukTracking): void
    {
        config(['query-ricer-extreme.absence_tracking.unique_key' => $ukTracking]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::find(9999);
        User::find(9999);

        $this->assertSame(1, $queries, 'PK absence is always tracked regardless of uk_tracking');
    }

    // -------------------------------------------------------------------------
    // Unique-key hit respects three config shapes
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function uniqueKeyConfigProvider(): array
    {
        return [
            'no unique key config' => ['none',     false],
            'single-column uk' => ['single',   true],
            'compound uk (2 cols)' => ['compound', false],
        ];
    }

    #[DataProvider('uniqueKeyConfigProvider')]
    public function test_unique_key_hit_respects_config(string $configShape, bool $expectsCacheHit): void
    {
        $uniqueConfig = match ($configShape) {
            'none' => [],
            'single' => [User::class => ['unique' => [['email']]]],
            'compound' => [User::class => ['unique' => [['email', 'name']]]],
            default => [],
        };

        config(['query-ricer-extreme.models' => $uniqueConfig]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        $this->store->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $found = User::where('email', $user->email)->first();

        $this->assertSame($expectsCacheHit ? 0 : 1, $queries);
        $this->assertNotNull($found);
    }

    // -------------------------------------------------------------------------
    // Compound unique key: both columns required for a hit
    // -------------------------------------------------------------------------

    /** @return array<string, array{bool, bool, bool}> */
    public static function compoundUkQueryProvider(): array
    {
        return [
            'both columns → hit' => [true,  true,  true],
            'only email → miss' => [true,  false, false],
            'only name → miss' => [false, true,  false],
            'neither column → miss' => [false, false, false],
        ];
    }

    #[DataProvider('compoundUkQueryProvider')]
    public function test_compound_unique_key_requires_all_columns(bool $includeEmail, bool $includeName, bool $expectsCacheHit): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['email', 'name']]],
        ]]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        $this->store->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $builder = User::query();
        if ($includeEmail) {
            $builder->where('email', $user->email);
        }
        if ($includeName) {
            $builder->where('name', 'Alice');
        }
        if (! $includeEmail && ! $includeName) {
            $builder->where('active', true);
        }

        $found = $builder->first();

        $this->assertSame($expectsCacheHit ? 0 : 1, $queries);

        if ($includeEmail || $includeName) {
            $this->assertNotNull($found);
        }
    }

    // -------------------------------------------------------------------------
    // Multiple configured indexes — second tried when first doesn't match
    // -------------------------------------------------------------------------

    /** @return array<string, array{string, bool}> */
    public static function multiIndexProvider(): array
    {
        return [
            'query by email → second index hits' => ['email', true],
            'query by name  → first index hits' => ['name',  true],
        ];
    }

    #[DataProvider('multiIndexProvider')]
    public function test_multiple_indexes_tried_in_order(string $queryColumn, bool $expectsCacheHit): void
    {
        config(['query-ricer-extreme.models' => [
            User::class => ['unique' => [['name'], ['email']]],
        ]]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);
        $this->store->flush();
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $found = User::where($queryColumn, $user->$queryColumn)->first();

        $this->assertSame($expectsCacheHit ? 0 : 1, $queries);
        $this->assertNotNull($found);
    }

    // -------------------------------------------------------------------------
    // UK absence tracking toggle — first() and exists()
    // -------------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function uniqueAbsenceMethodProvider(): array
    {
        return [
            'first()' => ['first'],
            'exists()' => ['exists'],
        ];
    }

    #[DataProvider('uniqueAbsenceMethodProvider')]
    public function test_unique_absence_not_recorded_when_toggle_disabled(string $method): void
    {
        config([
            'query-ricer-extreme.models' => [User::class => ['unique' => [['email']]]],
            'query-ricer-extreme.absence_tracking.unique_key' => false,
        ]);

        if ($method === 'first') {
            User::where('email', 'nobody@example.com')->first();
        } else {
            User::where('email', 'nobody@example.com')->exists();
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        if ($method === 'first') {
            User::where('email', 'nobody@example.com')->first();
        } else {
            User::where('email', 'nobody@example.com')->exists();
        }

        $this->assertSame(1, $queries, "{$method}() must hit SQL again when uk absence tracking is disabled");
    }

    #[DataProvider('uniqueAbsenceMethodProvider')]
    public function test_unique_absence_recorded_when_toggle_enabled(string $method): void
    {
        config([
            'query-ricer-extreme.models' => [User::class => ['unique' => [['email']]]],
            'query-ricer-extreme.absence_tracking.unique_key' => true,
        ]);

        if ($method === 'first') {
            User::where('email', 'nobody@example.com')->first();
        } else {
            User::where('email', 'nobody@example.com')->exists();
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        if ($method === 'first') {
            User::where('email', 'nobody@example.com')->first();
        } else {
            User::where('email', 'nobody@example.com')->exists();
        }

        $this->assertSame(0, $queries, "{$method}() must skip SQL when uk absence tracking is enabled");
    }

    // -------------------------------------------------------------------------
    // Full permutation: pk_tracking × uk_tracking × unique_config — identity
    // map is always correct for present models
    // -------------------------------------------------------------------------

    /** @return array<string, array{bool, bool, string}> */
    public static function fullPermutationProvider(): array
    {
        $result = [];

        foreach ([true, false] as $pk) {
            foreach ([true, false] as $uk) {
                foreach (['none', 'single', 'compound'] as $shape) {
                    $label = "pk={$pk} uk={$uk} shape={$shape}";
                    $result[$label] = [$pk, $uk, $shape];
                }
            }
        }

        return $result;
    }

    #[DataProvider('fullPermutationProvider')]
    public function test_identity_map_consistent_for_present_model_across_all_configs(bool $pkTracking, bool $ukTracking, string $shape): void
    {
        $uniqueConfig = match ($shape) {
            'single' => [User::class => ['unique' => [['email']]]],
            'compound' => [User::class => ['unique' => [['email', 'name']]]],
            default => [],
        };

        config([
            'query-ricer-extreme.absence_tracking.primary_key' => $pkTracking,
            'query-ricer-extreme.absence_tracking.unique_key' => $ukTracking,
            'query-ricer-extreme.models' => $uniqueConfig,
        ]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);

        $byId1 = User::find($user->id);
        $this->assertInstanceOf(User::class, $byId1);
        $this->assertSame($user->id, $byId1->id);

        $byId2 = User::find($user->id);
        $this->assertSame($byId1, $byId2, 'Second find must return same instance from map');
    }
}
