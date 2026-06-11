<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Store\IdentityMapStore;
use Vusys\QueryRicerExtreme\Tests\Jobs\WorkerProbeJob;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

/**
 * Octane and queue workers reuse one booted process across many request/job
 * turns. Correctness depends on every turn boundary fully flushing per-request
 * state — the store, but also the static ScopeFingerprinter caches and the
 * SchemaDiscovery cache — so a second turn can never serve a first turn's
 * instance. These tests drive two turns through one process and assert the
 * second starts cold.
 */
final class WorkerLoopTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync']);
        WorkerProbeJob::reset();
    }

    #[Test]
    public function a_second_request_turn_serves_nothing_from_the_first(): void
    {
        // --- Request turn 1: warm the store and the static caches ---
        $user = User::create(['name' => 'A', 'email' => 'a@example.com']);
        User::find($user->id);

        $warmQueries = 0;
        DB::listen(function () use (&$warmQueries): void {
            $warmQueries++;
        });
        $reread = User::query()->whereKey($user->id)->first();
        $this->assertSame('A', $reread?->name);
        $this->assertSame(0, $warmQueries, 'turn 1 re-read is served from memory');

        $this->assertGreaterThan(0, resolve(IdentityMapStore::class)->debugStats()['entries']);
        $this->assertNotEmpty($this->scopeFingerprinterCache());

        // --- Request boundary ---
        $this->terminateRequest();

        // --- Request turn 2: everything must be cold ---
        $this->assertSame(0, resolve(IdentityMapStore::class)->debugStats()['entries'], 'store survived the boundary');
        $this->assertEmpty($this->scopeFingerprinterCache(), 'ScopeFingerprinter statics survived the boundary');
        $this->assertEmpty($this->schemaDiscoveryCache(), 'SchemaDiscovery cache survived the boundary');

        $coldQueries = 0;
        DB::listen(function () use (&$coldQueries): void {
            $coldQueries++;
        });
        $turn2 = User::query()->whereKey($user->id)->first();
        $this->assertGreaterThan(0, $coldQueries, 'turn 2 must go to SQL, not serve a stale instance');
        $this->assertSame('A', $turn2?->name);
    }

    #[Test]
    public function each_sync_job_starts_with_a_flushed_map(): void
    {
        // Leave entries behind from the dispatching "request".
        $user = User::create(['name' => 'Caller', 'email' => 'caller@example.com']);
        User::find($user->id);
        $this->assertGreaterThan(0, resolve(IdentityMapStore::class)->debugStats()['entries']);

        dispatch(new WorkerProbeJob('one'));
        dispatch(new WorkerProbeJob('two'));

        $this->assertSame([0, 0], WorkerProbeJob::$entriesAtStart, 'every job must begin with a flushed store');
    }

    #[Test]
    public function a_failing_job_does_not_leak_into_the_next_job(): void
    {
        try {
            dispatch(new WorkerProbeJob('boom', throwAfterWarming: true));
            $this->fail('the failing job should have surfaced its exception');
        } catch (RuntimeException $e) {
            $this->assertSame('probe job failure', $e->getMessage());
        }

        dispatch(new WorkerProbeJob('after'));

        $this->assertSame([0, 0], WorkerProbeJob::$entriesAtStart, 'a thrown job must still leave the next job a clean store');
    }

    private function terminateRequest(): void
    {
        $app = app();
        $app->terminate();
    }

    /** @return array<string, mixed> */
    private function scopeFingerprinterCache(): array
    {
        $property = new ReflectionProperty(ScopeFingerprinter::class, 'usesSoftDeletesCache');
        $value = $property->getValue();

        return is_array($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    private function schemaDiscoveryCache(): array
    {
        $property = new ReflectionProperty(SchemaDiscovery::class, 'uniqueIndexCache');
        $value = $property->getValue(resolve(SchemaDiscovery::class));

        return is_array($value) ? $value : [];
    }
}
