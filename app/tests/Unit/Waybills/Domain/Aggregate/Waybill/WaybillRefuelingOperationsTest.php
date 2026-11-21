<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
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
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refueling;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillRefuelingOperationsTest extends TestCase
{
    public function test_several_refuelings_keep_types_dates_and_exact_total(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $first = $this->refueling($vehicle, '2026-01-09T12:00:00+03:00', '0.1');
        $second = $this->refueling($vehicle, '2026-01-11T18:00:00+03:00', '0.20000000000000000001', FuelType::Ai92);
        $refuelings = new RefuelingOperations($first, $second);

        $sheet->assertRefuelingOperations($sheet->period(), null, $this->route($vehicle), $refuelings);

        self::assertSame([$first, $second], $refuelings->all());
        self::assertSame('0.30000000000000000001', (string) $refuelings->total());
        self::assertSame(FuelType::Ai95, $first->fuelType);
        self::assertSame(FuelType::Ai92, $second->fuelType);
        self::assertSame('30', (string) $vehicle->fuelRemaining());
    }

    public function test_fuel_types_are_derived_from_refuelings_without_duplicates(): void
    {
        [$sheet, $vehicle] = $this->draft();
        self::assertSame([], $sheet->refueledFuelTypes());
        $refuelings = new RefuelingOperations(
            $this->refueling($vehicle, '2026-01-09', '1'),
            $this->refueling($vehicle, '2026-01-10', '2', FuelType::Ai92),
            $this->refueling($vehicle, '2026-01-11', '3'),
        );
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), null, null, null, $refuelings);

        self::assertSame([FuelType::Ai95, FuelType::Ai92], $sheet->refueledFuelTypes());
    }

    public function test_refuelings_can_be_entered_before_the_route(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $refuelings = new RefuelingOperations($this->refueling($vehicle, '2026-01-10', '12.5'));

        $sheet->assertRefuelingOperations($sheet->period(), null, null, $refuelings);
        self::assertSame('12.5', (string) $refuelings->total());
    }

    public function test_rejects_refueling_of_another_vehicle(): void
    {
        [$sheet] = $this->draft();
        [, $other] = $this->draft();
        $refuelings = new RefuelingOperations($this->refueling($other, '2026-01-10', '1'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $sheet->assertRefuelingOperations($sheet->period(), null, null, $refuelings);
    }

    public function test_rejects_refueling_outside_the_period(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $refuelings = new RefuelingOperations($this->refueling($vehicle, '2026-02-01', '1'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('вне периода');
        $sheet->assertRefuelingOperations($sheet->period(), null, null, $refuelings);
    }

    public function test_rejects_refueling_during_absence(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $refuelings = new RefuelingOperations($this->refueling($vehicle, '2026-01-10', '1'));
        $absence = WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-09'), new \DateTimeImmutable('2026-01-11'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('период без поездок');
        $sheet->assertRefuelingOperations($sheet->period(), null, null, $refuelings, $absence);
    }

    public function test_rejects_refueling_without_a_nearby_trip_when_route_is_present(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $refuelings = new RefuelingOperations($this->refueling($vehicle, '2026-01-13', '1'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('поездка текущего листа');
        $sheet->assertRefuelingOperations($sheet->period(), null, $this->route($vehicle), $refuelings);
    }

    public function test_does_not_replace_legacy_total_with_separate_refuelings(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $legacy = new Fuel(30, null, new Refuelings(new PositiveNumber(20)), 'АИ-95');
        $refuelings = new RefuelingOperations($this->refueling($vehicle, '2026-01-10', '1'));
        $sheet->reviseDraft($sheet->period(), null, $legacy, null);
        try {
            $sheet->assertRefuelingOperations($sheet->period(), null, null, $refuelings);
            self::fail('Общая сумма не должна пропасть или задвоиться.');
        } catch (AppException $exception) {
            self::assertStringContainsString('общей сумме', $exception->getMessage());
        }
        self::assertSame(20, $legacy->refuelings()->total());
    }

    public function test_new_refuelings_participate_in_consumption(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $refuelings = new RefuelingOperations(
            $this->refueling($vehicle, '2026-01-09T12:00:00+03:00', '0.1'),
            $this->refueling($vehicle, '2026-01-11T18:00:00+03:00', '0.20000000000000000001', FuelType::Ai92),
        );
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), 101, new Fuel(30, 9.7, null, 'АИ-95'), $this->route($vehicle), $refuelings);

        self::assertSame('20.60000000000000000001', (string) $sheet->fuelConsumed());
        self::assertEqualsWithDelta(2060.0, $sheet->actualConsumptionPer100Km(), 0.000001);
    }

    public function test_new_refuelings_allow_negative_consumption(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $refuelings = new RefuelingOperations($this->refueling($vehicle, '2026-01-10', '20'));
        $sheet->reviseDraftWithRefuelingOperations($sheet->period(), 101, new Fuel(30, 55, null, 'АИ-95'), $this->route($vehicle), $refuelings);

        self::assertSame('-5', (string) $sheet->fuelConsumed());
        self::assertSame(-500.0, $sheet->actualConsumptionPer100Km());
    }

    public function test_legacy_refuelings_cannot_be_removed(): void
    {
        [$sheet] = $this->draft();
        $legacy = new Fuel(30, null, new Refuelings(new PositiveNumber(20)), 'АИ-95');
        $sheet->reviseDraft($sheet->period(), null, $legacy, null);

        try {
            $sheet->reviseDraft($sheet->period(), null, null, null);
            self::fail('Ранее сохранённые заправки нельзя удалить.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Ранее сохранённые заправки', $exception->getMessage());
        }
        self::assertSame($legacy, $sheet->fuel());
    }

    public function test_legacy_refuelings_cannot_be_replaced(): void
    {
        [$sheet] = $this->draft();
        $original = new Refuelings(null, new Refueling(new \DateTimeImmutable('2026-01-10'), new PositiveNumber(5)));
        $sheet->reviseDraft($sheet->period(), null, new Fuel(30, null, $original, 'АИ-95'), null);

        try {
            $sheet->reviseDraft($sheet->period(), null, new Fuel(30, null, new Refuelings(new PositiveNumber(6)), 'АИ-95'), null);
            self::fail('Ранее сохранённые заправки нельзя заменить другими данными.');
        } catch (AppException $exception) {
            self::assertStringContainsString('Ранее сохранённые заправки', $exception->getMessage());
        }
        self::assertSame($original, $sheet->fuel()->refuelingsOrNull());
    }

    public function test_revision_accepts_an_equal_copy_of_legacy_refuelings(): void
    {
        [$sheet] = $this->draft();
        $sheet->reviseDraft($sheet->period(), null, new Fuel(30, null, new Refuelings(new PositiveNumber(20)), 'АИ-95'), null);

        $sheet->reviseDraft($sheet->period(), null, new Fuel(30, 25, new Refuelings(new PositiveNumber(20)), 'АИ-95'), null);

        self::assertSame(25, $sheet->fuel()->remaining());
        self::assertSame(20, $sheet->fuel()->refuelings()->total());
    }

    public function test_rejects_duplicate_refueling_id(): void
    {
        [, $vehicle] = $this->draft();
        $operation = $this->refueling($vehicle, '2026-01-10', '1');
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('дважды');
        new RefuelingOperations($operation, clone $operation);
    }

    public function test_rejects_reconciliation_in_refueling_list(): void
    {
        [$sheet, $vehicle] = $this->draft();
        $operation = new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-10'), FuelType::Ai95, BigDecimal::of('-1'), FuelOperationType::Reconciliation, $sheet);
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('только заправки');
        new RefuelingOperations($operation);
    }

    /** @return array{Waybill, Vehicle} */
    private function draft(): array
    {
        $base = new Location('База');
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), $base, 100, BigDecimal::of('30'), null, FuelType::Ai95, FuelType::Ai95, FuelType::Ai92);
        $vehicle->enter(new DriverProfile(Uuid::v7(), 'owner', new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), $base));

        return [Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31'))), $vehicle];
    }

    private function route(Vehicle $vehicle): Route
    {
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2026-01-10'), new Destination(new Location('Клиент'), 1), RouteLogOrigin::Manual);

        return new Route($vehicle, $trip, $trip);
    }

    private function refueling(Vehicle $vehicle, string $date, string $amount, FuelType $type = FuelType::Ai95): FuelOperation
    {
        return new FuelOperation(Uuid::v7(), $vehicle, new \DateTimeImmutable($date), $type, BigDecimal::of($amount), FuelOperationType::Refueling);
    }
}
