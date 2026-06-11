<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Models\User;

/**
 * A real queued job used to prove the identity map is flushed at job boundaries.
 * It records how many entries the store held the moment its handler began — if
 * the previous job (or the dispatching request) left entries behind, this number
 * would be non-zero.
 */
final class WorkerProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var list<int> entries observed at the start of each handled job */
    public static array $entriesAtStart = [];

    public function __construct(
        private readonly string $emailSuffix,
        private readonly bool $throwAfterWarming = false,
    ) {}

    public static function reset(): void
    {
        self::$entriesAtStart = [];
    }

    public function handle(IdentityMapStore $store): void
    {
        $entries = $store->debugStats()['entries'];
        self::$entriesAtStart[] = is_int($entries) ? $entries : -1;

        $user = User::create(['name' => 'Probe', 'email' => "probe-{$this->emailSuffix}@example.com"]);
        User::find($user->id);

        if ($this->throwAfterWarming) {
            throw new RuntimeException('probe job failure');
        }
    }
}
