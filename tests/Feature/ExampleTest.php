<?php

declare(strict_types=1);

namespace Vusys\QueryRicerExtreme\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Vusys\QueryRicerExtreme\Tests\TestCase;

final class ExampleTest extends TestCase
{
    #[Test]
    public function service_provider_loads(): void
    {
        $this->assertNotNull($this->app);
    }
}
