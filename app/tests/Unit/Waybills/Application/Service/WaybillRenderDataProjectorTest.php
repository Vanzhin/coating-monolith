<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Application\Service;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Waybills\Application\Service\WaybillRenderDataProjector;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperationType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RefuelingOperations;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRenderDataProjectorTest extends TestCase
{
    public function test_full_waybill_projects_identity_exact_fuel_and_route_rows(): void
    {
        $vehicle = $this->vehicle();
        $period = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31 23:59'));
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10 12:00'), new Destination(new Location('Россия', 'Москва'), 5, 'Склад'), RouteLogOrigin::Manual);
        $sheet = Waybill::create(Uuid::v7(), 1, $vehicle, $period, 105, new Fuel('30.00000000000000000001', 20, new Refuelings(), 'АИ-95'), new Route($vehicle, $trip, $trip));
        $refueling = new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-10 12:00'), FuelType::Ai95, BigDecimal::of('12.5'), FuelOperationType::Refueling);
        $sheet->reviseDraftWithRefuelingOperations($period, 105, $sheet->fuel(), $sheet->route(), new RefuelingOperations($refueling));

        $data = (new WaybillRenderDataProjector())->project($sheet);

        self::assertSame('1', $this->text($data->get('number')));
        self::assertSame('A123BC77', $this->text($data->get('registration_number')));
        self::assertSame('Иванов Иван Иванович', $this->text($data->get('driver_name')));
        self::assertSame('Иванов И. И.', $this->text($data->get('driver_short_name')));
        self::assertSame('12 34 567890', $this->text($data->get('license_number')));
        self::assertSame('112-233-445 95', $this->text($data->get('snils')));
        self::assertSame('105', $this->text($data->get('odometer_end')));
        self::assertSame('5', $this->text($data->get('distance')));
        self::assertSame('12.5', $this->text($data->get('fuel_refilled')));
        self::assertSame('22.50000000000000000001', $this->text($data->get('fuel_actual')));
        self::assertSame('0.5', $this->text($data->get('fuel_standard')));
        self::assertSame('22.00000000000000000001', $this->text($data->get('fuel_excess')));
        self::assertSame('', $this->text($data->get('fuel_saved')));
        $routes = $data->get('routes');
        self::assertInstanceOf(RepeatValue::class, $routes);
        self::assertSame([[
            'date' => '10.01.2026 12:00', 'odometer_start' => '100', 'odometer_end' => '105',
            'from' => 'Россия, База', 'to' => 'Россия, Москва, Склад', 'distance' => '5',
        ]], $routes->rows);
    }

    public function test_fuel_type_is_derived_from_unique_refueling_types(): void
    {
        $vehicle = $this->vehicle();
        $vehicle->changeFuelTypes(FuelType::Ai95, FuelType::Ai95, FuelType::Ai92);
        $sheet = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $refuelings = new RefuelingOperations(
            new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-10 12:00'), FuelType::Ai95, BigDecimal::of('1'), FuelOperationType::Refueling),
            new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-11 12:00'), FuelType::Ai92, BigDecimal::of('2'), FuelOperationType::Refueling),
            new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-12 12:00'), FuelType::Ai95, BigDecimal::of('3'), FuelOperationType::Refueling),
        );
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), null, null, null, $refuelings);

        $data = (new WaybillRenderDataProjector())->project($sheet);

        self::assertSame('АИ-95, АИ-92', $this->text($data->get('fuel_type')));
    }

    public function test_early_draft_does_not_turn_unknown_fuel_into_zero(): void
    {
        $vehicle = $this->vehicle();
        $sheet = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));

        $data = (new WaybillRenderDataProjector())->project($sheet);

        self::assertSame('', $this->text($data->get('fuel_refilled')));
        self::assertSame('', $this->text($data->get('fuel_type')));
        self::assertSame('', $this->text($data->get('fuel_end')));
        self::assertSame('', $this->text($data->get('fuel_actual')));
        self::assertSame('100', $this->text($data->get('odometer_end')));
        self::assertSame('0', $this->text($data->get('distance')));
        $routes = $data->get('routes');
        self::assertInstanceOf(RepeatValue::class, $routes);
        self::assertSame([], $routes->rows);
    }

    private function text(mixed $value): string
    {
        self::assertInstanceOf(TextValue::class, $value);

        return $value->value;
    }

    private function vehicle(): Vehicle
    {
        $base = new Location('Россия', 'База');
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 100, BigDecimal::of('30.00000000000000000001'), new PositiveNumber(10));
        $driver = new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Иванов Иван Иванович', '1234567890', new \DateTimeImmutable('2020-01-02'), '11223344595'), $base);
        $vehicle->enter($driver);

        return $vehicle;
    }
}
