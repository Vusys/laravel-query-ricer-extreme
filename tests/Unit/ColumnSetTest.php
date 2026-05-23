<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Vusys\QueryRicerExtreme\Coverage\ColumnSet;

final class ColumnSetTest extends TestCase
{
    #[Test]
    public function wildcard_set_covers_any_specific_columns(): void
    {
        $set = new ColumnSet(['*']);

        $this->assertTrue($set->allColumns);
        $this->assertTrue($set->covers(['id', 'name', 'email']));
        $this->assertTrue($set->covers(['id']));
        $this->assertTrue($set->covers([]));
    }

    #[Test]
    public function wildcard_set_covers_wildcard_request(): void
    {
        $set = new ColumnSet(['*']);

        $this->assertTrue($set->covers(['*']));
    }

    #[Test]
    public function specific_column_set_covers_subset_of_its_columns(): void
    {
        $set = new ColumnSet(['id', 'name', 'email']);

        $this->assertFalse($set->allColumns);
        $this->assertTrue($set->covers(['id', 'name']));
        $this->assertTrue($set->covers(['id']));
        $this->assertTrue($set->covers(['name', 'email']));
        $this->assertTrue($set->covers([]));
    }

    #[Test]
    public function specific_column_set_does_not_cover_missing_column(): void
    {
        $set = new ColumnSet(['id', 'name']);

        $this->assertFalse($set->covers(['id', 'name', 'email']));
        $this->assertFalse($set->covers(['email']));
    }

    #[Test]
    public function specific_column_set_does_not_cover_wildcard_request(): void
    {
        // A partial ColumnSet cannot satisfy a request for all columns.
        $set = new ColumnSet(['id', 'name']);

        $this->assertFalse($set->covers(['*']));
    }
}
