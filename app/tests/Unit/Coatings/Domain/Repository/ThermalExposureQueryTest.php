<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Repository;

use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Coatings\Domain\Repository\ThermalExposureQuery;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class ThermalExposureQueryTest extends TestCase
{
    public function testAcceptsSaneTemperature(): void
    {
        $q = new ThermalExposureQuery(90, ThermalEnvironment::DRY_HEAT, true);

        self::assertSame(90, $q->temperature);
        self::assertSame(ThermalEnvironment::DRY_HEAT, $q->environment);
        self::assertTrue($q->includingPeak);
    }

    public function testRejectsTooLowTemperature(): void
    {
        $this->expectException(AppException::class);
        new ThermalExposureQuery(-500, ThermalEnvironment::DRY_HEAT);
    }

    public function testRejectsTooHighTemperature(): void
    {
        $this->expectException(AppException::class);
        new ThermalExposureQuery(9999, ThermalEnvironment::DRY_HEAT);
    }
}
