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

    public function test_restores_coordinates_from_a_single_matching_route_location(): void
    {
        $settings = new RouteSettings(
            new Location('База', latitude: 56.8, longitude: 60.6),
            new Destination(new Location('Завод', latitude: 56.9, longitude: 60.7), 10),
        );

        self::assertSame(56.8, $settings->restoreCoordinatesForUnchangedLocation(new Location('База'))->latitude);
        self::assertSame(56.9, $settings->restoreCoordinatesForUnchangedLocation(new Location('Завод'))->latitude);
        self::assertNull($settings->restoreCoordinatesForUnchangedLocation(new Location('Гостиница'))->latitude);
    }

    public function test_does_not_guess_coordinates_when_route_settings_are_ambiguous(): void
    {
        $settings = new RouteSettings(
            new Location('База'),
            new Destination(new Location('Завод', latitude: 56.9, longitude: 60.7), 10),
            new Destination(new Location('Завод', latitude: 57.0, longitude: 61.0), 12),
        );

        self::assertNull($settings->restoreCoordinatesForUnchangedLocation(new Location('Завод'))->latitude);
    }

    public function test_distinguishes_enterprises_at_the_same_legacy_address(): void
    {
        $settings = new RouteSettings(
            new Location('База'),
            new Destination(new Location('Завод', enterprise: 'Первое', latitude: 56.9, longitude: 60.7), 10),
            new Destination(new Location('Завод', enterprise: 'Второе', latitude: 57.0, longitude: 61.0), 12),
        );

        self::assertSame(57.0, $settings->restoreCoordinatesForUnchangedLocation(new Location('Завод', enterprise: 'Второе'))->latitude);
        self::assertNull($settings->restoreCoordinatesForUnchangedLocation(new Location('Завод', enterprise: 'Третье'))->latitude);
    }

    public function test_uses_consistent_coordinates_from_duplicate_legacy_locations(): void
    {
        $settings = new RouteSettings(
            new Location('База'),
            new Destination(new Location('Завод', latitude: 56.9, longitude: 60.7), 10),
            new Destination(new Location('Завод', latitude: 56.9, longitude: 60.7), 12),
        );

        self::assertSame(56.9, $settings->restoreCoordinatesForUnchangedLocation(new Location('Завод'))->latitude);
    }
}
