<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use PHPUnit\Framework\TestCase;

final class DestinationTest extends TestCase
{
    public function test_stores_location_distance_and_trimmed_enterprise(): void
    {
        $location = new Location('Склад');
        $distance = 15;
        $destination = new Destination($location, $distance, '  Предприятие  ');

        self::assertSame($location, $destination->location);
        self::assertSame($distance, $destination->distance);
        self::assertSame('Предприятие', $destination->enterprise);
        self::assertTrue((new \ReflectionClass($destination))->isReadOnly());
    }

    public function test_fractional_kilometres_are_not_accepted(): void
    {
        $this->expectException(\TypeError::class);
        new Destination(new Location('Склад'), 1.5); // @phpstan-ignore-line
    }

    public function test_distance_must_be_positive(): void
    {
        $this->expectException(AppException::class);
        new Destination(new Location('Склад'), 0);
    }

    public function test_negative_distance_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Destination(new Location('Склад'), -1);
    }

    public function test_empty_enterprise_becomes_null(): void
    {
        self::assertNull((new Destination(new Location('Склад'), 1))->enterprise);
        self::assertNull((new Destination(new Location('Склад'), 1, " \t\n"))->enterprise);
    }
}
