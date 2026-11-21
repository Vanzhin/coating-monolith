<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Domain\Aggregate\Waybill;

use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
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
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class WaybillFuelReconciliationTest extends TestCase
{
    #[DataProvider('balances')]
    public function test_creates_reconciliation_from_initial_balance_and_refueling(
        string $initial,
        string $refueled,
        string $actual,
        string $expectedChange,
    ): void {
        $vehicle = $this->vehicle($initial);
        $draft = $this->draft($vehicle);
        $refueling = $this->refueling($vehicle, $refueled);
        $id = Uuid::v7();
        $date = new \DateTimeImmutable('2026-01-31 18:00:00+03:00');

        $reconciliation = $draft->createFuelReconciliation(
            $id, $vehicle, $date, FuelType::Ai95, BigDecimal::of($actual), $refueling,
        );

        self::assertSame($id, $reconciliation->id);
        self::assertSame($vehicle, $reconciliation->vehicle);
        self::assertSame($draft, $reconciliation->waybill);
        self::assertSame($date, $reconciliation->date);
        self::assertSame(FuelOperationType::Reconciliation, $reconciliation->type);
        self::assertTrue($reconciliation->change->isEqualTo($expectedChange));
        self::assertSame($initial, (string) $draft->fuelStart());
        self::assertSame($initial, (string) $vehicle->fuelRemaining());
        self::assertFalse($draft->isLocked());
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function balances(): iterable
    {
        yield 'consumption' => ['30', '20', '35', '-15'];
        yield 'positive correction' => ['30', '20', '55', '5'];
        yield 'unchanged' => ['30', '20', '50', '0'];
        yield 'precise fraction' => ['0.10000000000000000001', '0.20000000000000000002', '0.30000000000000000004', '0.00000000000000000001'];
    }

    public function test_reconciliation_without_refuelings_uses_initial_balance(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);

        $operation = $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'),
        );

        self::assertTrue($operation->change->isEqualTo('5'));
        self::assertSame('30', (string) $vehicle->fuelRemaining());
    }

    public function test_rejects_reconciliation_for_another_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $draft->createFuelReconciliation(
            Uuid::v7(), $this->vehicle(), new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'),
        );
    }

    public function test_rejects_refueling_for_another_vehicle(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $refueling = $this->refueling($this->vehicle(), '20');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('другому автомобилю');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'), $refueling,
        );
    }

    public function test_rejects_duplicate_refueling_ids(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $refueling = $this->refueling($vehicle, '20');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('дважды');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'), $refueling, clone $refueling,
        );
    }

    public function test_rejects_other_reconciliation_as_refueling(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $existing = FuelOperation::reconcile(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-10 10:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('30'), BigDecimal::of('25'), $draft,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('только заправки');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'), $existing,
        );
    }

    public function test_rejects_refueling_after_period_end(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $refueling = $this->refueling($vehicle, '20', '2026-01-31 18:00:00.000001+03:00');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('вне периода');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-02-01 10:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'), $refueling,
        );
    }

    public function test_rejects_refueling_after_reconciliation_time(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $refueling = $this->refueling($vehicle, '20', '2026-01-10 10:00:01+03:00');

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('позже сверки');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-10 10:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'), $refueling,
        );
    }

    public function test_rejects_reconciliation_before_period_start(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('раньше начала периода');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-01 07:59:59+03:00'),
            FuelType::Ai95, BigDecimal::of('35'),
        );
    }

    public function test_rejects_negative_actual_fuel(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Фактический остаток топлива');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('-1'),
        );
    }

    public function test_approve_creates_reconciliation_and_locks_sheet(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $draft->revise($draft->period(), 100, new Fuel(30, 35, new Refuelings(), 'АИ-95'), new Route($vehicle));
        $refueling = $this->refueling($vehicle, '20');
        $id = Uuid::v7();

        $operation = $draft->approve($id, $vehicle, $refueling);

        self::assertTrue($draft->isLocked());
        self::assertSame($id, $operation->id);
        self::assertSame('-15', (string) $operation->change);
        self::assertSame($draft->period()->to(), $operation->date);
        self::assertSame($draft, $operation->waybill);
    }

    public function test_approved_sheet_rejects_new_reconciliation(): void
    {
        $vehicle = $this->vehicle();
        $draft = $this->draft($vehicle);
        $draft->revise($draft->period(), 100, new Fuel(30, 30, new Refuelings(), 'АИ-95'), new Route($vehicle));
        $draft->lock();

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Зафиксированный');
        $draft->createFuelReconciliation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable('2026-01-31 18:00:00+03:00'),
            FuelType::Ai95, BigDecimal::of('35'),
        );
    }

    private function vehicle(string $initialFuel = '30'): Vehicle
    {
        return new Vehicle(
            Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'),
            new \DateTimeImmutable('2025-01-01 00:00:00+00:00'), new Location('А'), 100, BigDecimal::of($initialFuel),
        );
    }

    private function draft(Vehicle $vehicle): Waybill
    {
        $driver = new DriverProfile(
            Uuid::v7(), 'owner',
            new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
            $vehicle->initialLocation(),
        );
        $vehicle->enter($driver);

        return Waybill::createDraft(
            Uuid::v7(), 1, $vehicle,
            new DateTimeInterval(new \DateTimeImmutable('2026-01-01 08:00:00+03:00'), new \DateTimeImmutable('2026-01-31 18:00:00+03:00')),
        );
    }

    private function refueling(Vehicle $vehicle, string $amount, string $date = '2026-01-10 10:00:00+03:00'): FuelOperation
    {
        return new FuelOperation(
            Uuid::v7(), $vehicle, new \DateTimeImmutable($date), FuelType::Ai95,
            BigDecimal::of($amount), FuelOperationType::Refueling,
        );
    }
}
