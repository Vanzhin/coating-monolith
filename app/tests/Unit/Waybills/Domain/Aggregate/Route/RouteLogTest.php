<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class RouteLogTest extends TestCase
{
    public function test_stores_trip_data(): void
    {
        $date = new \DateTimeImmutable('2026-01-01');
        $vehicle = $this->vehicle();
        $destination = new Destination(new Location('Б'), 1, '  Склад  ');
        $settings = new RouteSettings($vehicle->initialLocation(), $destination);
        $log = new RouteLog(Uuid::v7(), $vehicle, null, $date, $settings->destinations()[0], RouteLogOrigin::Manual);
        $next = new RouteLog(Uuid::v7(), $vehicle, $log, $date, new Destination(new Location('В'), 2), RouteLogOrigin::Manual);

        self::assertSame($date, $log->date);
        self::assertSame($vehicle->initialLocation(), $log->departure());
        self::assertSame($destination, $log->destination);
        self::assertSame('Склад', $log->destination->enterprise);
        self::assertSame(1, $log->distanceInKilometers());
        self::assertSame($destination->location, $next->departure());
        self::assertSame(RouteLogOrigin::Manual, $log->origin);
        $idProperty = new \ReflectionProperty(RouteLog::class, 'id');
        self::assertTrue($idProperty->isPublic());
        self::assertTrue($idProperty->isPrivateSet());
        self::assertFalse($idProperty->isReadOnly());
        foreach (['vehicle', 'previous', 'date', 'destination', 'origin'] as $property) {
            self::assertTrue((new \ReflectionProperty(RouteLog::class, $property))->isReadOnly());
        }
    }

    public function test_id_cannot_be_changed_from_outside(): void
    {
        $log = new RouteLog(Uuid::v7(), $this->vehicle(), null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location('Б'), 1), RouteLogOrigin::Manual);
        $writeFromOutside = static function (object $object, string $property, mixed $value): void {
            $object->{$property} = $value;
        };

        $this->expectException(\Error::class);
        $writeFromOutside($log, 'id', Uuid::v7());
    }

    public function test_rejects_trip_to_same_location(): void
    {
        $this->expectException(AppException::class);
        new RouteLog(Uuid::v7(), $this->vehicle(), null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location(' А '), 1), RouteLogOrigin::Manual);
    }

    #[DataProvider('invalidDistances')]
    public function test_rejects_non_positive_distance(int $distance): void
    {
        $this->expectException(AppException::class);
        new RouteLog(Uuid::v7(), $this->vehicle(), null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location('Б'), $distance), RouteLogOrigin::Automatic);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidDistances(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function test_rejects_fractional_distance(): void
    {
        $this->expectException(\TypeError::class);
        new RouteLog(Uuid::v7(), $this->vehicle(), null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location('Б'), 1.5), RouteLogOrigin::Manual); // @phpstan-ignore-line
    }

    public function test_allows_trip_on_first_day_of_vehicle_history(): void
    {
        $vehicle = $this->vehicle('2026-01-01 12:00:00');

        $log = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-01 00:00:00'), new Destination(new Location('Б'), 1), RouteLogOrigin::Manual);

        self::assertSame('2026-01-01', $log->date->format('Y-m-d'));
    }

    public function test_rejects_trip_before_first_day_of_vehicle_history(): void
    {
        $vehicle = $this->vehicle('2026-01-02 00:00:00');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Дата поездки не может быть раньше начала истории автомобиля.');
        new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-01 23:59:59'), new Destination(new Location('Б'), 1), RouteLogOrigin::Manual);
    }

    public function test_with_previous_keeps_destination_and_original_unchanged(): void
    {
        $vehicle = $this->vehicle();
        $first = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-01'), new Destination(new Location('Б'), 1), RouteLogOrigin::Manual);
        $destination = new Destination(new Location('В'), 2, 'Предприятие');
        $original = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-02'), $destination, RouteLogOrigin::Manual);
        $changed = $original->withPrevious($first);

        self::assertNotSame($original, $changed);
        self::assertSame($original->id, $changed->id);
        self::assertNull($original->previous);
        self::assertSame($first, $changed->previous);
        self::assertSame($destination, $changed->destination);
        self::assertSame('Предприятие', $changed->destination->enterprise);
        self::assertSame(2, $changed->distanceInKilometers());
        self::assertSame($first->destination->location, $changed->departure());
    }

    private function vehicle(string $initialAt = '2025-01-01'): Vehicle
    {
        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable($initialAt), new Location('А'), 100, BigDecimal::of('20'));
    }
}
