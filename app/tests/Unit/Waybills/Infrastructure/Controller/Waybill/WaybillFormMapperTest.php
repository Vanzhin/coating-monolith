<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Controller\Waybill;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\VehicleDTO;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
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
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use App\Waybills\Infrastructure\Controller\Waybill\WaybillFormMapper;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillFormMapperTest extends TestCase
{
    public function test_create_input_leaves_final_odometer_empty_for_server_calculation(): void
    {
        $vehicle = new VehicleDTO((string) Uuid::v7(), 'Модель', 'A123BC77', ['АИ-95'], null, 42150, '30', new Location('Россия', 'База'), 'АИ-95');
        $mapper = new WaybillFormMapper(new LocationFormMapper());

        $input = $mapper->createInput($vehicle);

        self::assertSame('42150', $input['initialOdometer']);
        self::assertSame('', $input['odometerEnd']);
    }

    public function test_blank_trip_has_no_destination_on_create_and_edit(): void
    {
        $vehicle = new VehicleDTO((string) Uuid::v7(), 'Модель', 'A123BC77', ['АИ-95'], null, 100, '30', new Location('Россия', 'База'), 'АИ-95');
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $blank = $mapper->blankTrip();

        foreach (['locationId', 'country', 'city', 'cityType', 'street', 'house', 'enterprise'] as $field) {
            self::assertSame('', $blank[$field], $field);
        }
        self::assertSame($blank, $mapper->createInput($vehicle)['blankTrip']);
        self::assertSame($blank, $mapper->input($this->draft(), 1)['blankTrip']);
    }

    public function test_round_trip_normalizes_period_and_preserves_trip_time(): void
    {
        $waybill = $this->draft();
        $date = new \DateTimeImmutable('2026-01-10 12:00:00.123456', new \DateTimeZone('Europe/Berlin'));
        $trip = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, $date, new Destination(new Location('Россия', 'Москва'), 5, 'Склад'), RouteLogOrigin::Manual);
        $waybill->setTrips($trip);
        $mapper = new WaybillFormMapper(new LocationFormMapper());

        $command = $mapper->updateCommand($waybill, $mapper->input($waybill, 1));

        self::assertSame('2026-01-01 00:00:00.000000', $command->period->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-31 23:59:59.999999', $command->period->to()->format('Y-m-d H:i:s.u'));
        self::assertNull($command->fuel);
        self::assertSame(105, $command->odometerEnd);
        self::assertTrue($command->routePresent);
        self::assertCount(1, $command->trips);
        self::assertSame((string) $trip->id, (string) $command->trips[0]->id);
        self::assertSame($date, $command->trips[0]->date);
        self::assertSame('Склад', $command->trips[0]->destination->enterprise);
        self::assertSame(5, $command->trips[0]->destination->distance);
    }

    public function test_trip_coordinates_come_from_the_unchanged_stored_snapshot(): void
    {
        $waybill = $this->draft();
        $location = new Location('Россия', 'Москва', 'Лесная', '1', latitude: 55.7, longitude: 37.6, id: Uuid::v7());
        $trip = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2026-01-10'), new Destination($location, 5), RouteLogOrigin::Manual);
        $waybill->setTrips($trip);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['trips'][0]['latitude'] = '1';
        $input['trips'][0]['longitude'] = '2';

        $command = $mapper->updateCommand($waybill, $input);
        self::assertSame(55.7, $command->trips[0]->destination->location->latitude);
        self::assertSame(37.6, $command->trips[0]->destination->location->longitude);

        $input['trips'][0]['house'] = '2';
        self::assertNull($mapper->updateCommand($waybill, $input)->trips[0]->destination->location->latitude);
        $input['trips'][0]['house'] = '1';
        $input['trips'][0]['locationId'] = (string) Uuid::v7();
        self::assertNull($mapper->updateCommand($waybill, $input)->trips[0]->destination->location->latitude);
    }

    public function test_blank_remaining_is_mapped_without_rounding_initial_balance(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['refuelings'] = [['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '3.50000000000000000001']];

        $command = $mapper->updateCommand($waybill, $input);

        self::assertNull($command->fuel);
        self::assertSame('30.00000000000000000001', (string) $waybill->fuelStart());
        self::assertNotNull($command->refuelings);
        self::assertSame('3.50000000000000000001', (string) $command->refuelings->items[0]->quantity);
    }

    public function test_refueling_round_trip_preserves_id_timezone_microseconds_and_quantity(): void
    {
        $waybill = $this->draft();
        $date = new \DateTimeImmutable('2026-01-10 12:00:00.123456', new \DateTimeZone('Europe/Berlin'));
        $operation = new FuelOperation(Uuid::v7(), $waybill->vehicle(), $date, FuelType::Ai95, BigDecimal::of('0.123456789012345678901'), FuelOperationType::Refueling);
        $waybill->reviseDraftWithRefuelingOperations($waybill->period(), null, new Fuel('30.00000000000000000001', 30, new Refuelings(), 'АИ-95'), null, new RefuelingOperations($operation));
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['refuelings'][0]['date'] = '2026-01-10T12:00';

        $command = $mapper->updateCommand($waybill, $input);

        self::assertNotNull($command->refuelings);
        self::assertSame((string) $operation->id, (string) $command->refuelings->items[0]->id);
        self::assertSame($date, $command->refuelings->items[0]->date);
        self::assertSame('0.123456789012345678901', (string) $command->refuelings->items[0]->quantity);
    }

    public function test_unchanged_period_dates_cover_full_days_in_original_timezone(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());

        $command = $mapper->updateCommand($waybill, $mapper->input($waybill, 1));

        self::assertSame('2026-01-01 00:00:00.000000', $command->period->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-31 23:59:59.999999', $command->period->to()->format('Y-m-d H:i:s.u'));
        self::assertSame('Europe/Berlin', $command->period->from()->getTimezone()->getName());
        self::assertSame('Europe/Berlin', $command->period->to()->getTimezone()->getName());
    }

    public function test_changed_period_dates_cover_whole_days(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['from'] = '2026-02-01';
        $input['to'] = '2026-02-28';

        $command = $mapper->updateCommand($waybill, $input);

        self::assertSame('2026-02-01 00:00:00.000000', $command->period->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-02-28 23:59:59.999999', $command->period->to()->format('Y-m-d H:i:s.u'));
    }

    public function test_absence_round_trip_covers_full_days_in_original_timezone(): void
    {
        $waybill = $this->draft();
        $from = new \DateTimeImmutable('2026-01-10 08:00:00.987654', new \DateTimeZone('Europe/Berlin'));
        $to = new \DateTimeImmutable('2026-01-11 18:00:00.123456', new \DateTimeZone('Europe/Berlin'));
        $waybill->reviseDraft($waybill->period(), null, new Fuel('30.00000000000000000001', 30, new Refuelings(), 'АИ-95'), null, WaybillPeriod::fromDates($from, $to));
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        self::assertSame([['from' => '2026-01-10', 'to' => '2026-01-11']], $input['absences']);

        $command = $mapper->updateCommand($waybill, $input);

        self::assertCount(1, $command->absences);
        self::assertSame('2026-01-10 00:00:00.000000', $command->absences[0]->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-11 23:59:59.999999', $command->absences[0]->to()->format('Y-m-d H:i:s.u'));
        self::assertSame('Europe/Berlin', $command->absences[0]->from()->getTimezone()->getName());
        self::assertSame('Europe/Berlin', $command->absences[0]->to()->getTimezone()->getName());

        $input['absences'][0]['from'] = '2026-01-10T08:00';
        $input['absences'][0]['to'] = '2026-01-11T18:00';

        $command = $mapper->updateCommand($waybill, $input);

        self::assertCount(1, $command->absences);
        self::assertSame('2026-01-10 00:00:00.000000', $command->absences[0]->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-11 23:59:59.999999', $command->absences[0]->to()->format('Y-m-d H:i:s.u'));
    }

    public function test_new_and_changed_absences_cover_the_entire_final_day(): void
    {
        $waybill = $this->draft();
        $original = WaybillPeriod::fromDates(
            new \DateTimeImmutable('2026-01-10 08:00:00.987654', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-01-11 18:00:00.123456', new \DateTimeZone('Europe/Berlin')),
        );
        $waybill->reviseDraft($waybill->period(), null, new Fuel('30.00000000000000000001', 30, new Refuelings(), 'АИ-95'), null, $original);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['absences'] = [
            ['from' => '2026-01-10', 'to' => '2026-01-12'],
            ['from' => '2026-01-15', 'to' => '2026-01-15'],
        ];

        $command = $mapper->updateCommand($waybill, $input);

        self::assertSame('2026-01-10 00:00:00.000000', $command->absences[0]->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-12 23:59:59.999999', $command->absences[0]->to()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-15 00:00:00.000000', $command->absences[1]->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-15 23:59:59.999999', $command->absences[1]->to()->format('Y-m-d H:i:s.u'));
    }

    public function test_new_absences_reject_time_instead_of_ignoring_it(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['absences'] = [['from' => '2026-01-10T08:00', 'to' => '2026-01-11T18:00']];

        $this->expectException(AppException::class);
        $mapper->updateCommand($waybill, $input);
    }

    public function test_removing_an_absence_preserves_remaining_timezone_and_normalizes_boundaries(): void
    {
        $waybill = $this->draft();
        $first = WaybillPeriod::fromDates(
            new \DateTimeImmutable('2026-01-05 08:00:00.987654', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-01-06 09:00:00.987654', new \DateTimeZone('Europe/Berlin')),
        );
        $from = new \DateTimeImmutable('2026-01-10 12:00:00.123456', new \DateTimeZone('Europe/Moscow'));
        $to = new \DateTimeImmutable('2026-01-11 13:00:00.123456', new \DateTimeZone('Europe/Moscow'));
        $second = WaybillPeriod::fromDates($from, $to);
        $waybill->reviseDraft($waybill->period(), null, new Fuel('30.00000000000000000001', 30, new Refuelings(), 'АИ-95'), null, $first, $second);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        unset($input['absences'][0]);
        $input['absences'] = array_values($input['absences']);

        $command = $mapper->updateCommand($waybill, $input);

        self::assertCount(1, $command->absences);
        self::assertSame('2026-01-10 00:00:00.000000', $command->absences[0]->from()->format('Y-m-d H:i:s.u'));
        self::assertSame('2026-01-11 23:59:59.999999', $command->absences[0]->to()->format('Y-m-d H:i:s.u'));
        self::assertSame('Europe/Moscow', $command->absences[0]->from()->getTimezone()->getName());
        self::assertSame('Europe/Moscow', $command->absences[0]->to()->getTimezone()->getName());
    }

    public function test_blank_fuel_input_is_mapped_to_null_for_domain_validation(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());

        $fuel = $mapper->updateCommand($waybill, $mapper->input($waybill, 1))->fuel;

        self::assertNull($fuel);
    }

    public function test_empty_refueling_list_means_zero(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['fuelRemaining'] = '30';

        $fuel = $mapper->updateCommand($waybill, $input)->fuel;

        self::assertNotNull($fuel);
        self::assertNotNull($fuel->refuelingsOrNull());
        self::assertSame(0, $fuel->refuelings()->total());
    }

    public function test_update_preserves_legacy_refuelings(): void
    {
        $waybill = $this->draft();
        $legacy = new Refuelings(new PositiveNumber(20));
        $waybill->reviseDraft($waybill->period(), null, new Fuel('30.00000000000000000001', 30, $legacy, 'АИ-95'), null);
        $mapper = new WaybillFormMapper(new LocationFormMapper());

        $command = $mapper->updateCommand($waybill, $mapper->input($waybill, 1));

        self::assertNotNull($command->fuel);
        self::assertSame($legacy, $command->fuel->refuelingsOrNull());
    }

    public function test_unrepresentable_entered_fuel_is_rejected_instead_of_rounded(): void
    {
        $waybill = $this->draft();
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $input = $mapper->input($waybill, 1);
        $input['fuelRemaining'] = '0.123456789012345678901';

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('без потери данных');
        $mapper->updateCommand($waybill, $input);
    }

    public function test_normalized_absence_rejects_an_existing_trip_without_changing_the_draft(): void
    {
        $waybill = $this->draft();
        $absence = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-10 12:00', new \DateTimeZone('Europe/Berlin')), new \DateTimeImmutable('2026-01-10 14:00', new \DateTimeZone('Europe/Berlin')));
        $trip = new RouteLog(Uuid::v7(), $waybill->vehicle(), null, new \DateTimeImmutable('2026-01-10 00:00', new \DateTimeZone('Europe/Berlin')), new Destination(new Location('Россия', 'Москва'), 5), RouteLogOrigin::Manual);
        $waybill->setTrips($trip);
        $waybill->reviseDraft($waybill->period(), 105, new Fuel('30.00000000000000000001', 30, new Refuelings(), 'АИ-95'), $waybill->route(), $absence);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $command = $mapper->updateCommand($waybill, $mapper->input($waybill, 1));
        $repository = $this->createStub(WaybillRepositoryInterface::class);
        $repository->method('hasExistingTripIds')->willReturn(false);
        $route = (new WaybillRouteBuilder($repository))->build($waybill, null, true, ...$command->trips);

        try {
            $waybill->reviseDraft($command->period, $command->odometerEnd, $command->fuel, $route, ...$command->absences);
            self::fail('Поездка в отображаемый день отсутствия должна быть отклонена.');
        } catch (AppException $exception) {
            self::assertSame('Поездка попадает в период без поездок.', $exception->getMessage());
        }
        self::assertSame('12:00', $waybill->absences()[0]->from()->format('H:i'));
        self::assertSame('14:00', $waybill->absences()[0]->to()->format('H:i'));
    }

    public function test_normalized_absence_rejects_an_existing_refueling_without_changing_the_draft(): void
    {
        $waybill = $this->draft();
        $absence = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-10 12:00', new \DateTimeZone('Europe/Berlin')), new \DateTimeImmutable('2026-01-10 14:00', new \DateTimeZone('Europe/Berlin')));
        $operation = new FuelOperation(Uuid::v7(), $waybill->vehicle(), new \DateTimeImmutable('2026-01-10 00:00', new \DateTimeZone('Europe/Berlin')), FuelType::Ai95, BigDecimal::of('3'), FuelOperationType::Refueling);
        $fuel = new Fuel('30.00000000000000000001', 30, new Refuelings(), 'АИ-95');
        $waybill->reviseDraftWithRefuelingOperations($waybill->period(), 100, $fuel, null, new RefuelingOperations($operation), $absence);
        $mapper = new WaybillFormMapper(new LocationFormMapper());
        $command = $mapper->updateCommand($waybill, $mapper->input($waybill, 1));

        try {
            $waybill->reviseDraftWithRefuelingOperations($command->period, $command->odometerEnd, $command->fuel, null, $waybill->refuelingOperations(), ...$command->absences);
            self::fail('Заправка в отображаемый день отсутствия должна быть отклонена.');
        } catch (AppException $exception) {
            self::assertSame('Заправка попадает в период без поездок.', $exception->getMessage());
        }
        self::assertSame('12:00', $waybill->absences()[0]->from()->format('H:i'));
        self::assertSame('14:00', $waybill->absences()[0]->to()->format('H:i'));
    }

    private function draft(): Waybill
    {
        $base = new Location('Россия', 'База');
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 100, BigDecimal::of('30.00000000000000000001'));
        $vehicle->enter(new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), $base));
        $from = new \DateTimeImmutable('2026-01-01 08:00:00.987654', new \DateTimeZone('Europe/Berlin'));
        $to = new \DateTimeImmutable('2026-01-31 18:00:00.123456', new \DateTimeZone('Europe/Berlin'));

        return Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval($from, $to));
    }
}
