<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Tests\Models\User;

#[Group('performance')]
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

    #[Test]
    public function repeated_find_with_identity_map(): void
    {
        $id = $this->userId;

        $this->bench('identity-map-hit', function () use ($id): void {
            for ($i = 0; $i < 100; $i++) {
                User::find($id);
            }
        });
    }

    #[Test]
    public function absent_key_tracking(): void
    {
        $this->bench('absent-key-tracking', function (): void {
            for ($i = 0; $i < 100; $i++) {
                User::find(99999);
            }
        });
    }

    #[Test]
    public function repeated_find_without_identity_map(): void
    {
        $id = $this->userId;

        $this->bench('no-identity-map', function () use ($id): void {
            for ($i = 0; $i < 100; $i++) {
                User::query()->withoutIdentityMap()->find($id);
            }
        });
    }
}
