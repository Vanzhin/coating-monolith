<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleStateTest extends TestCase
{
    public function test_initial_state_is_also_current_state(): void
    {
        $location = new Location('А');
        $initialAt = new \DateTimeImmutable('2025-12-31 17:45:12.123456+03:00');
        $vehicle = $this->vehicle(100, '20.25', $location, $initialAt);

        self::assertSame($initialAt, $vehicle->initialAt());
        self::assertSame('2025-12-31 17:45:12.123456+03:00', $vehicle->initialAt()->format('Y-m-d H:i:s.uP'));
        self::assertSame($location, $vehicle->initialLocation());
        self::assertSame(100, $vehicle->initialOdometer());
        self::assertSame(100, $vehicle->currentOdometer());
        self::assertSame('20.25', (string) $vehicle->initialFuelRemaining());
        self::assertSame('20.25', (string) $vehicle->fuelRemaining());
    }

    public function test_zero_initial_readings_and_empty_route_are_valid(): void
    {
        $vehicle = $this->vehicle(0, 0);
        $vehicle->approveRoute(new Route($vehicle), null, BigDecimal::of('0'));

        self::assertSame(0, $vehicle->initialOdometer());
        self::assertSame('0', (string) $vehicle->initialFuelRemaining());
        self::assertSame(0, $vehicle->currentOdometer());
        self::assertSame('0', (string) $vehicle->fuelRemaining());
    }

    public function test_approving_linked_routes_updates_current_readings_but_not_initial_state(): void
    {
        $vehicle = $this->vehicle(100, 20);
        $initialLocation = $vehicle->initialLocation();
        $first = $this->log($vehicle, null, '2026-01-01', 'Б', 2);
        $second = $this->log($vehicle, $first, '2026-01-02', 'В', 3);
        $vehicle->approveRoute(new Route($vehicle, $first, $second), null, BigDecimal::of('17.5'));

        self::assertSame(105, $vehicle->currentOdometer());
        self::assertSame('17.5', (string) $vehicle->fuelRemaining());

        $third = $this->log($vehicle, $second, '2026-02-01', 'А', 4);
        $vehicle->approveRoute(new Route($vehicle, $third, $third), $second, BigDecimal::of('0'));

        self::assertSame(100, $vehicle->initialOdometer());
        self::assertSame('20', (string) $vehicle->initialFuelRemaining());
        self::assertSame($initialLocation, $vehicle->initialLocation());
        self::assertSame(109, $vehicle->currentOdometer());
        self::assertSame('0', (string) $vehicle->fuelRemaining());
    }

    public function test_fractional_fuel_readings_are_preserved_exactly(): void
    {
        $vehicle = $this->vehicle(100, '0.1');
        $vehicle->approveRoute(new Route($vehicle), null, BigDecimal::of('0.3'));

        self::assertSame('0.1', (string) $vehicle->initialFuelRemaining());
        self::assertSame('0.3', (string) $vehicle->fuelRemaining());
    }

    public function test_odometer_overflow_is_rejected_without_changing_vehicle(): void
    {
        $vehicle = $this->vehicle(PHP_INT_MAX, 20);
        $trip = $this->log($vehicle, null, '2026-01-01', 'Б', 1);

        try {
            $vehicle->approveRoute(new Route($vehicle, $trip, $trip), null, BigDecimal::of('10'));
            self::fail('Пробег не должен переполняться.');
        } catch (AppException $exception) {
            self::assertStringContainsString('одометра слишком велико', $exception->getMessage());
            self::assertSame(PHP_INT_MAX, $vehicle->currentOdometer());
            self::assertSame('20', (string) $vehicle->fuelRemaining());
        }
    }

    #[DataProvider('invalidReadings')]
    public function test_rejects_invalid_initial_odometer(int $reading): void
    {
        $this->expectException(AppException::class);
        $this->vehicle($reading, 0);
    }

    #[DataProvider('negativeFuelReadings')]
    public function test_rejects_invalid_initial_fuel(string $reading): void
    {
        $this->expectException(AppException::class);
        $this->vehicle(0, $reading);
    }

    /** @return iterable<string, array{string}> */
    public static function negativeFuelReadings(): iterable
    {
        yield 'negative integer' => ['-1'];
        yield 'negative fraction' => ['-0.5'];
    }

    #[DataProvider('nonFiniteFuelReadings')]
    public function test_non_finite_fuel_is_not_a_decimal(string $reading): void
    {
        $this->expectException(NumberFormatException::class);
        BigDecimal::of($reading);
    }

    /** @return iterable<string, array{string}> */
    public static function nonFiniteFuelReadings(): iterable
    {
        yield 'positive infinity' => ['INF'];
        yield 'negative infinity' => ['-INF'];
        yield 'not a number' => ['NAN'];
    }

    /** @return iterable<string, array{int}> */
    public static function invalidReadings(): iterable
    {
        yield 'negative integer' => [-1];
    }

    #[DataProvider('nonIntegerOdometerReadings')]
    public function test_rejects_non_integer_initial_odometer(float $reading): void
    {
        $this->expectException(\TypeError::class);
        $this->vehicle($reading, 0);
    }

    /** @return iterable<string, array{float}> */
    public static function nonIntegerOdometerReadings(): iterable
    {
        yield 'negative fraction' => [-0.5];
        yield 'positive fraction' => [100.5];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('negativeFuelReadings')]
    public function test_rejects_invalid_fuel_on_approval_without_mutation(string $reading): void
    {
        $vehicle = $this->vehicle(100, 20);
        $first = $this->log($vehicle, null, '2026-01-01', 'Б', 2);

        try {
            $vehicle->approveRoute(new Route($vehicle, $first, $first), null, BigDecimal::of($reading));
            self::fail('Некорректный остаток топлива должен отклоняться.');
        } catch (AppException) {
            self::assertSame(100, $vehicle->currentOdometer());
            self::assertSame('20', (string) $vehicle->fuelRemaining());
        }
    }

    #[DataProvider('nonFiniteFuelReadings')]
    public function test_non_finite_fuel_cannot_be_submitted_on_approval(string $reading): void
    {
        $vehicle = $this->vehicle(100, 20);
        $first = $this->log($vehicle, null, '2026-01-01', 'Б', 2);

        try {
            $vehicle->approveRoute(new Route($vehicle, $first, $first), null, BigDecimal::of($reading));
            self::fail('Неконечное значение топлива не является десятичным числом.');
        } catch (NumberFormatException) {
            self::assertSame(100, $vehicle->currentOdometer());
            self::assertSame('20', (string) $vehicle->fuelRemaining());
        }
    }

    public function test_rejects_wrong_previous_without_mutating_vehicle(): void
    {
        $vehicle = $this->vehicle(100, 20);
        $approved = $this->log($vehicle, null, '2026-01-01', 'Б', 5);
        $vehicle->approveRoute(new Route($vehicle, $approved, $approved), null, BigDecimal::of('18'));
        $next = $this->log($vehicle, $approved, '2026-01-02', 'В', 2);
        $wrongPrevious = $this->log($vehicle, null, '2026-01-01', 'Г', 1);

        try {
            $vehicle->approveRoute(new Route($vehicle, $next, $next), $wrongPrevious, BigDecimal::of('10'));
            self::fail('Маршрут с неверной предыдущей поездкой должен отклоняться.');
        } catch (AppException) {
            self::assertSame(100, $vehicle->initialOdometer());
            self::assertSame(105, $vehicle->currentOdometer());
            self::assertSame('18', (string) $vehicle->fuelRemaining());
        }
    }

    public function test_rejects_route_of_another_vehicle_without_mutation(): void
    {
        $vehicle = $this->vehicle(100, 20);
        $other = $this->vehicle(0, 0);
        $trip = $this->log($other, null, '2026-01-01', 'Б', 1);

        try {
            $vehicle->approveRoute(new Route($other, $trip, $trip), null, BigDecimal::of('10'));
            self::fail('Маршрут другого автомобиля должен отклоняться.');
        } catch (AppException) {
            self::assertSame(100, $vehicle->currentOdometer());
            self::assertSame('20', (string) $vehicle->fuelRemaining());
        }
    }

    public function test_rejects_previous_trip_of_another_vehicle_without_mutation(): void
    {
        $vehicle = $this->vehicle(100, 20);
        $other = $this->vehicle(0, 0);
        $previous = $this->log($other, null, '2026-01-01', 'Б', 1);
        $trip = $this->log($vehicle, null, '2026-01-02', 'В', 1);

        try {
            $vehicle->approveRoute(new Route($vehicle, $trip, $trip), $previous, BigDecimal::of('10'));
            self::fail('Предыдущая поездка другого автомобиля должна отклоняться.');
        } catch (AppException) {
            self::assertSame(100, $vehicle->currentOdometer());
            self::assertSame('20', (string) $vehicle->fuelRemaining());
        }
    }

    private function vehicle(int|float $odometer, int|string $fuel, ?Location $location = null, ?\DateTimeImmutable $initialAt = null): Vehicle
    {
        return new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), $initialAt ?? new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), $location ?? new Location('А'), $odometer, BigDecimal::of($fuel));
    }

    private function log(Vehicle $vehicle, ?RouteLog $previous, string $date, string $destination, int $distance): RouteLog
    {
        return new RouteLog(Uuid::v7(), $vehicle, $previous, new \DateTimeImmutable($date), new Destination(new Location($destination), $distance), RouteLogOrigin::Manual);
    }
}
