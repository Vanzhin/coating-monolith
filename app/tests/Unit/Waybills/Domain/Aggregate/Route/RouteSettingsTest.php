<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use PHPUnit\Framework\TestCase;

final class RouteSettingsTest extends TestCase
{
    public function test_stores_configured_destination_and_distance(): void
    {
        $destination = new Destination(new Location('А'), 14, 'Предприятие');
        $settings = new RouteSettings(
            new Location('База'),
            $destination,
        );

        self::assertSame([$destination], $settings->destinations());
        self::assertSame(14, $settings->destinations()[0]->distance);
        self::assertSame('Предприятие', $settings->destinations()[0]->enterprise);
    }

    public function test_destination_cannot_be_base(): void
    {
        $this->expectException(AppException::class);
        new RouteSettings(new Location('База'), new Destination(new Location('База'), 1));
    }
}
