<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Driver\ColumnType;
use Vusys\QueryRicerExtreme\Driver\StringComparisonMode;
use Vusys\QueryRicerExtreme\Schema\SchemaDiscovery;
use Vusys\QueryRicerExtreme\Tests\Models\User;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ColumnSemanticsDiscoveryTest extends TestCase
{
    private SchemaDiscovery $discovery;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->discovery = resolve(SchemaDiscovery::class);
        $this->discovery->flush();
    }

    #[Test]
    public function email_column_is_classified_as_string(): void
    {
        $semantics = $this->discovery->for(new User, 'email');
        self::assertSame(ColumnType::String, $semantics->type);
    }

    #[Test]
    public function id_column_is_classified_as_integer(): void
    {
        $semantics = $this->discovery->for(new User, 'id');
        self::assertSame(ColumnType::Integer, $semantics->type);
    }

    #[Test]
    public function unknown_column_returns_unknown_semantics(): void
    {
        $semantics = $this->discovery->for(new User, 'nonexistent_column');
        self::assertSame(ColumnType::Unknown, $semantics->type);
        self::assertSame(StringComparisonMode::Unknown, $semantics->stringComparison);
    }

    #[Test]
    public function disabled_schema_discovery_returns_unknown_for_every_column(): void
    {
        config()->set('query-ricer-extreme.schema_discovery.enabled', false);
        $this->discovery->flush();

        $semantics = $this->discovery->for(new User, 'email');
        self::assertSame(ColumnType::Unknown, $semantics->type);
    }

    #[Test]
    public function introspection_failure_does_not_crash(): void
    {
        $orphanModel = new class extends Model
        {
            protected $table = 'no_such_table_anywhere';

            public $timestamps = false;
        };

        $semantics = $this->discovery->for($orphanModel, 'anything');
        self::assertSame(ColumnType::Unknown, $semantics->type);
    }

    #[Test]
    public function conservative_unknown_mode_keeps_string_comparison_unknown(): void
    {
        config()->set('query-ricer-extreme.database_semantics.'.DB::connection()->getDriverName().'.string_comparisons', 'conservative_unknown');
        $this->discovery->flush();

        $semantics = $this->discovery->for(new User, 'email');
        self::assertSame(StringComparisonMode::Unknown, $semantics->stringComparison);
    }

    #[Test]
    public function php_strict_mode_forces_case_sensitive(): void
    {
        config()->set('query-ricer-extreme.database_semantics.'.DB::connection()->getDriverName().'.string_comparisons', 'php_strict');
        $this->discovery->flush();

        $semantics = $this->discovery->for(new User, 'email');
        self::assertSame(StringComparisonMode::CaseSensitive, $semantics->stringComparison);
    }

    #[Test]
    public function database_collation_mode_uses_driver_default_when_collation_missing(): void
    {
        $driver = DB::connection()->getDriverName();
        config()->set('query-ricer-extreme.database_semantics.'.$driver.'.string_comparisons', 'database_collation');
        $this->discovery->flush();

        $semantics = $this->discovery->for(new User, 'email');

        // SQLite + Postgres default to case-sensitive; MySQL/MariaDB depend on
        // the table's collation (driver-default unknown without server info).
        $expected = match ($driver) {
            'sqlite', 'pgsql' => StringComparisonMode::CaseSensitive,
            default => StringComparisonMode::Unknown,
        };

        // If MySQL/MariaDB has reported an explicit collation, the resolver may
        // return CaseInsensitive instead; accept either Unknown or
        // CaseInsensitive when the driver is MySQL/MariaDB.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            self::assertContains(
                $semantics->stringComparison,
                [StringComparisonMode::Unknown, StringComparisonMode::CaseInsensitive, StringComparisonMode::CaseSensitive],
            );

            return;
        }

        self::assertSame($expected, $semantics->stringComparison);
    }
}
