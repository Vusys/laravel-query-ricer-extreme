<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Vusys\QueryRicerExtreme\Query\ScopeFingerprinter;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ScopeFingerprinterTest extends TestCase
{
    public function test_from_model_returns_default_for_non_soft_delete_model(): void
    {
        $model = new class extends Model
        {
            protected $table = 'users';
        };

        $this->assertSame('default', ScopeFingerprinter::fromModel($model));
    }

    public function test_from_model_returns_soft_delete_default_when_not_deleted(): void
    {
        $model = new User;
        // deleted_at not set → not trashed
        $this->assertSame('soft-delete:default', ScopeFingerprinter::fromModel($model));
    }

    public function test_from_model_returns_with_trashed_when_deleted_at_is_set(): void
    {
        $model = new User;
        $model->forceFill(['deleted_at' => now()]);

        $this->assertSame('soft-delete:with-trashed', ScopeFingerprinter::fromModel($model));
    }

    public function test_from_builder_returns_default_for_normal_query(): void
    {
        $fingerprint = ScopeFingerprinter::fromBuilder(User::query());

        $this->assertSame('soft-delete:default', $fingerprint);
    }

    public function test_from_builder_returns_with_trashed_when_soft_delete_scope_removed(): void
    {
        $fingerprint = ScopeFingerprinter::fromBuilder(User::withTrashed());

        $this->assertSame('soft-delete:with-trashed', $fingerprint);
    }

    public function test_fingerprint_is_deterministic(): void
    {
        $a = ScopeFingerprinter::fromBuilder(User::query());
        $b = ScopeFingerprinter::fromBuilder(User::query());

        $this->assertSame($a, $b);
    }

    public function test_same_model_produces_same_fingerprint(): void
    {
        $model = new User;

        $this->assertSame(
            ScopeFingerprinter::fromModel($model),
            ScopeFingerprinter::fromModel($model),
        );
    }

    public function test_trashed_and_non_trashed_produce_different_fingerprints(): void
    {
        $live = new User;
        $trashed = new User;
        $trashed->forceFill(['deleted_at' => now()]);

        $this->assertNotSame(
            ScopeFingerprinter::fromModel($live),
            ScopeFingerprinter::fromModel($trashed),
        );
    }

    public function test_withoutglobalscope_query_produces_different_fingerprint(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'active' => true]);

        $fpDefault = ScopeFingerprinter::fromBuilder(User::query());
        $fpWithoutScope = ScopeFingerprinter::fromBuilder(User::withoutGlobalScope(SoftDeletingScope::class));
        $this->assertNotSame($fpDefault, $fpWithoutScope, 'withoutGlobalScope must produce a different scope fingerprint');

        $default = User::find($alice->id);
        $this->assertInstanceOf(User::class, $default);

        $withoutScope = User::withoutGlobalScope(SoftDeletingScope::class)->find($alice->id);
        $this->assertInstanceOf(User::class, $withoutScope);

        $this->assertSame($alice->id, $default->id);
        $this->assertSame($alice->id, $withoutScope->id);
    }
}
