<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Performance;

use Vusys\QueryRicerExtreme\Tests\Models\User;

/**
 * @group performance
 */
final class IdentityMapBenchmarkTest extends PerformanceTestCase
{
    private int $userId;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $user = User::create(['name' => 'Bench User', 'email' => 'bench@example.com']);
        $this->userId = $user->id;
    }

    public function test_repeated_find_with_identity_map(): void
    {
        $id = $this->userId;

        $this->bench('identity-map-hit', function () use ($id): void {
            for ($i = 0; $i < 100; $i++) {
                User::find($id);
            }
        });
    }

    public function test_absent_key_tracking(): void
    {
        $this->bench('absent-key-tracking', function (): void {
            for ($i = 0; $i < 100; $i++) {
                User::find(99999);
            }
        });
    }

    public function test_repeated_find_without_identity_map(): void
    {
        $id = $this->userId;

        $this->bench('no-identity-map', function () use ($id): void {
            for ($i = 0; $i < 100; $i++) {
                User::withoutIdentityMap()->find($id);
            }
        });
    }
}
