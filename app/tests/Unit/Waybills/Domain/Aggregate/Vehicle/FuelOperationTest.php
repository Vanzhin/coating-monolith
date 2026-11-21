<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperation;
use App\Waybills\Domain\Aggregate\Vehicle\FuelOperationType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Fuel;
use App\Waybills\Domain\Aggregate\Waybill\Fuel\Refuelings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Aggregate\Waybill\WaybillPeriod;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class FuelOperationTest extends TestCase
{
    public function test_refueling_stores_positive_decimal_and_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $id = Uuid::v7();
        $date = new \DateTimeImmutable('2026-01-01 10:30:00+03:00');
        $change = BigDecimal::of('20.25');

        $operation = new FuelOperation($id, $vehicle, $date, FuelType::Ai95, $change, FuelOperationType::Refueling);

        self::assertSame($id, $operation->id);
        self::assertSame($vehicle, $operation->vehicle);
        self::assertSame($date, $operation->date);
        self::assertSame(FuelType::Ai95, $operation->fuelType);
        self::assertSame('20.25', (string) $operation->change);
        self::assertSame(FuelOperationType::Refueling, $operation->type);
        self::assertNull($operation->waybill);
    }

    #[DataProvider('nonPositiveAmounts')]
    public function test_refueling_must_be_positive(string $amount): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('положительным');

        $this->operation($this->vehicle(), $amount, FuelOperationType::Refueling);
    }

    /** @return iterable<string, array{string}> */
    public static function nonPositiveAmounts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-0.1'];
    }

    #[DataProvider('reconciliationAmounts')]
    public function test_reconciliation_preserves_signed_change_and_waybill(string $amount): void
    {
        $vehicle = $this->vehicle();
        $waybill = $this->waybill($vehicle);
        $operation = $this->operation($vehicle, $amount, FuelOperationType::Reconciliation, $waybill);

        self::assertSame($amount, (string) $operation->change);
        self::assertSame(FuelOperationType::Reconciliation, $operation->type);
        self::assertSame($waybill, $operation->waybill);
    }

    /** @return iterable<string, array{string}> */
    public static function reconciliationAmounts(): iterable
    {
        yield 'consumption' => ['-15'];
        yield 'positive correction' => ['5'];
        yield 'unchanged balance' => ['0'];
    }

    public function test_reconciliation_requires_waybill(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('путевой лист');

        $this->operation($this->vehicle(), '-15', FuelOperationType::Reconciliation);
    }

    #[DataProvider('reconciliationBalances')]
    public function test_reconciliation_calculates_signed_change(string $calculated, string $actual, string $expected): void
    {
        $vehicle = $this->vehicle();
        $id = Uuid::v7();
        $waybill = $this->waybill($vehicle);
        $date = new \DateTimeImmutable('2026-01-01 10:00:00+00:00');

        $operation = FuelOperation::reconcile(
            $id, $vehicle, $date, FuelType::Ai95,
            BigDecimal::of($calculated), BigDecimal::of($actual), $waybill,
        );

        self::assertSame($id, $operation->id);
        self::assertSame($vehicle, $operation->vehicle);
        self::assertSame($date, $operation->date);
        self::assertSame(FuelType::Ai95, $operation->fuelType);
        self::assertSame($expected, (string) $operation->change);
        self::assertSame(FuelOperationType::Reconciliation, $operation->type);
        self::assertSame($waybill, $operation->waybill);
        self::assertSame('30', (string) $vehicle->fuelRemaining());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function reconciliationBalances(): iterable
    {
        yield 'consumption' => ['50', '35', '-15'];
        yield 'positive correction' => ['50', '55', '5'];
        yield 'same balance' => ['50', '50', '0'];
        yield 'precise fractional correction' => ['30.1', '30.3', '0.2'];
    }

    public function test_reconciliation_rejects_negative_calculated_balance(): void
    {
        $vehicle = $this->vehicle();
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Расчётный остаток топлива');

        FuelOperation::reconcile(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-01'), FuelType::Ai95,
            BigDecimal::of('-1'), BigDecimal::of('0'), $this->waybill($vehicle),
        );
    }

    public function test_reconciliation_rejects_negative_actual_balance(): void
    {
        $vehicle = $this->vehicle();
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Фактический остаток топлива');

        FuelOperation::reconcile(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-01'), FuelType::Ai95,
            BigDecimal::of('50'), BigDecimal::of('-1'), $this->waybill($vehicle),
        );
    }

    public function test_rejects_reconciliation_for_another_vehicles_waybill(): void
    {
        $vehicle = $this->vehicle();
        $waybill = $this->waybill($this->vehicle());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        FuelOperation::reconcile(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-01'), FuelType::Ai95,
            BigDecimal::of('50'), BigDecimal::of('35'), $waybill,
        );
    }

    public function test_rejects_refueling_linked_to_another_vehicles_waybill(): void
    {
        $vehicle = $this->vehicle();
        $waybill = $this->waybill($this->vehicle());

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $this->operation($vehicle, '1', FuelOperationType::Refueling, $waybill);
    }

    public function test_matches_waybill_vehicle_by_identity_not_object_instance(): void
    {
        $vehicle = $this->vehicle();
        $waybill = $this->waybill($vehicle);
        $sameVehicle = clone $vehicle;

        $operation = $this->operation($sameVehicle, '-15', FuelOperationType::Reconciliation, $waybill);

        self::assertSame($waybill, $operation->waybill);
    }

    public function test_rejects_fuel_type_not_supported_by_vehicle(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('вид топлива');

        $this->operation($this->vehicle(), '1', FuelOperationType::Refueling, fuelType: FuelType::Ai92);
    }

    public function test_rejects_operation_before_vehicle_history(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('раньше начала истории автомобиля');

        $this->operation($this->vehicle(), '1', FuelOperationType::Refueling, date: new \DateTimeImmutable('2025-01-01 00:30:00+03:00'));
    }

    public function test_allows_operation_at_start_of_vehicle_history_in_another_time_zone(): void
    {
        $date = new \DateTimeImmutable('2025-01-01 03:00:00+03:00');
        $operation = $this->operation($this->vehicle(), '1', FuelOperationType::Refueling, date: $date);

        self::assertSame($date, $operation->date);
    }

    public function test_changes_can_be_summed_without_floating_point_error(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->operation($vehicle, '0.1', FuelOperationType::Refueling);
        $second = $this->operation($vehicle, '0.2', FuelOperationType::Refueling);

        self::assertSame('0.3', (string) $first->change->plus($second->change));
    }

    public function test_vehicle_calculates_remaining_fuel_from_initial_state(): void
    {
        $vehicle = $this->vehicle();
        $refueling = $this->operation($vehicle, '20', FuelOperationType::Refueling);
        $reconciliation = $this->operation($vehicle, '-15', FuelOperationType::Reconciliation, $this->waybill($vehicle));

        self::assertSame('35', (string) $vehicle->fuelRemainingAfter($refueling, $reconciliation));
        self::assertSame('30', (string) $vehicle->fuelRemaining());
        self::assertSame('30', (string) $vehicle->initialFuelRemaining());
    }

    public function test_positive_reconciliation_increases_calculated_balance(): void
    {
        $vehicle = $this->vehicle();
        $refueling = $this->operation($vehicle, '20', FuelOperationType::Refueling);
        $reconciliation = $this->operation($vehicle, '5', FuelOperationType::Reconciliation, $this->waybill($vehicle));

        self::assertSame('55', (string) $vehicle->fuelRemainingAfter($refueling, $reconciliation));
    }

    public function test_balance_calculation_preserves_decimal_precision(): void
    {
        $vehicle = $this->vehicle();
        $first = $this->operation($vehicle, '0.1', FuelOperationType::Refueling);
        $second = $this->operation($vehicle, '0.2', FuelOperationType::Refueling);

        self::assertSame('30.3', (string) $vehicle->fuelRemainingAfter($first, $second));
    }

    public function test_balance_calculation_without_operations_uses_initial_fuel(): void
    {
        self::assertSame('30', (string) $this->vehicle()->fuelRemainingAfter());
    }

    public function test_balance_calculation_rejects_operations_of_another_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $otherOperation = $this->operation($this->vehicle(), '10', FuelOperationType::Refueling);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $vehicle->fuelRemainingAfter($otherOperation);
    }

    public function test_balance_calculation_rejects_repeated_operation_ids(): void
    {
        $vehicle = $this->vehicle();
        $operation = $this->operation($vehicle, '10', FuelOperationType::Refueling);
        $duplicate = clone $operation;
        self::assertNotSame($operation, $duplicate);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('дважды');
        $vehicle->fuelRemainingAfter($operation, $duplicate);
    }

    public function test_balance_calculation_rejects_negative_remaining_fuel_without_mutating_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $reconciliation = $this->operation($vehicle, '-31', FuelOperationType::Reconciliation, $this->waybill($vehicle));

        try {
            $vehicle->fuelRemainingAfter($reconciliation);
            self::fail('Остаток топлива не может быть отрицательным.');
        } catch (AppException) {
            self::assertSame('30', (string) $vehicle->fuelRemaining());
            self::assertSame('30', (string) $vehicle->initialFuelRemaining());
        }
    }

    private function vehicle(): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), new Location('А'), 100, BigDecimal::of('30'),
        );
    }

    private function waybill(Vehicle $vehicle): Waybill
    {
        return Waybill::create(
            Uuid::v7(),
            1,
            $vehicle,
            WaybillPeriod::fromDates(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')),
            $vehicle->currentOdometer(),
            new Fuel(30, 30, new Refuelings(), 'АИ-95'),
            new Route($vehicle),
        );
    }

    private function operation(
        Vehicle $vehicle,
        string $amount,
        FuelOperationType $type,
        ?Waybill $waybill = null,
        FuelType $fuelType = FuelType::Ai95,
        ?\DateTimeImmutable $date = null,
    ): FuelOperation {
        return new FuelOperation(
            Uuid::v7(), $vehicle, $date ?? new \DateTimeImmutable('2026-01-01 10:00:00+00:00'),
            $fuelType, BigDecimal::of($amount), $type, $waybill,
        );
    }
}
