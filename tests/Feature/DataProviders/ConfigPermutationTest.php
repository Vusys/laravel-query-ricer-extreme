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
        config([
            'query-ricer-extreme.absence_tracking.unique_key' => $ukTracking,
        ]);

        resolve(IdentityMapStore::class)->flush();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        User::find(9999);
        User::find(9999);

        // PK absence is always tracked regardless of uk_tracking config
        $this->assertSame(1, $queries, 'Second find for missing PK should always skip SQL');
    }

    /** @return array<string, array{string}> */
    public static function uniqueKeyConfigProvider(): array
    {
        return [
            'no unique key config' => ['none'],
            'single-column unique key' => ['single'],
            'compound unique key' => ['compound'],
        ];
    }

    #[DataProvider('uniqueKeyConfigProvider')]
    public function test_unique_key_hit_respects_config(string $configShape): void
    {
        $uniqueConfig = match ($configShape) {
            'none' => [],
            'single' => [User::class => ['unique' => [['email']]]],
            'compound' => [User::class => ['unique' => [['email', 'name']]]],
            default => [],
        };

        config(['query-ricer-extreme.models' => $uniqueConfig]);

        $user = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
        resolve(IdentityMapStore::class)->flush();

        // Warm the cache
        User::find($user->id);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $found = User::where('email', 'alice@example.com')->first();

        if ($configShape === 'single') {
            $this->assertSame(0, $queries, 'Single-column unique hit should skip SQL');
            $this->assertNotNull($found);
        } else {
            $this->assertSame(1, $queries, 'Non-single unique-key config should execute SQL');
            $this->assertNotNull($found);
        }
    }

    /** @return array<string, array{bool, bool, string}> */
    public static function fullPermutationProvider(): array
    {
        $result = [];

        foreach ([true, false] as $pk) {
            foreach ([true, false] as $uk) {
                foreach (['none', 'single'] as $shape) {
                    $result["pk={$pk} uk={$uk} shape={$shape}"] = [$pk, $uk, $shape];
                }
            }
        }

        return $result;
    }

    #[DataProvider('fullPermutationProvider')]
    public function test_identity_map_is_consistent_across_config_combinations(bool $pkTracking, bool $ukTracking, string $shape): void
    {
        $uniqueConfig = $shape === 'single'
            ? [User::class => ['unique' => [['email']]]]
            : [];

        config([
            'query-ricer-extreme.absence_tracking.primary_key' => $pkTracking,
            'query-ricer-extreme.absence_tracking.unique_key' => $ukTracking,
            'query-ricer-extreme.models' => $uniqueConfig,
        ]);

        resolve(IdentityMapStore::class)->flush();

        $user = User::create(['name' => 'Alice', 'email' => 'alice-'.uniqid().'@example.com']);

        $byId = User::find($user->id);
        $this->assertInstanceOf(User::class, $byId);
        $this->assertSame($user->id, $byId->id);

        $byId2 = User::find($user->id);
        $this->assertSame($byId, $byId2);
    }
}
