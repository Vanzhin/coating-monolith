<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Route;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Repository\VehicleFilter;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private VehicleRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(VehicleRepositoryInterface::class);
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function test_round_trip_preserves_initial_state_and_approved_route_updates_current_state(): void
    {
        $initialAt = new \DateTimeImmutable('2026-11-01T12:34:56.123456+03:00');
        $vehicle = $this->vehicle('A123BC77', 'КамАЗ 65115', 123456, '42.75', new PositiveNumber(32.5), $initialAt);
        $this->repository->add($vehicle);
        $this->em->clear();

        $loaded = $this->repository->findOneById($vehicle->getId());
        self::assertNotNull($loaded);
        self::assertSame('КамАЗ 65115', $loaded->getModel());
        self::assertSame('a123bc77', $loaded->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai95, FuelType::Ai98], $loaded->getFuelTypes());
        self::assertSame(32.5, $loaded->getConsumptionRate()?->value());
        self::assertSame('2026-11-01T09:34:56.123456+00:00', $loaded->initialAt()->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(123456, $loaded->currentOdometer());
        self::assertSame('42.75', (string) $loaded->fuelRemaining());

        $trip = new RouteLog(Uuid::v7(), $loaded, null, new \DateTimeImmutable('2026-11-02'), new Destination(new Location('Россия', 'Назначение'), 44), RouteLogOrigin::Manual);
        $loaded->approveRoute(new Route($loaded, $trip, $trip), null, BigDecimal::of(10));
        $loaded->setConsumptionRate(null);
        $this->repository->add($loaded);
        $this->em->clear();

        $updated = $this->repository->findOneById($vehicle->getId());
        self::assertNotNull($updated);
        self::assertNull($updated->getConsumptionRate());
        self::assertSame(123500, $updated->currentOdometer());
        self::assertSame('10', (string) $updated->fuelRemaining());
        self::assertSame(123456, $updated->initialOdometer());
    }

    public function test_nullable_rate_and_default_fuel_type(): void
    {
        $vehicle = $this->vehicle('B124CC77');
        $this->repository->add($vehicle);
        $this->em->clear();

        $loaded = $this->repository->findOneById($vehicle->getId());
        self::assertNotNull($loaded);
        self::assertSame([FuelType::Ai95, FuelType::Ai98], $loaded->getFuelTypes());
        self::assertSame(FuelType::Ai95, $loaded->getDefaultFuelType());
        self::assertNull($loaded->getConsumptionRate());
        self::assertSame(0, $loaded->currentOdometer());
        self::assertSame('0', (string) $loaded->fuelRemaining());
        self::assertNull($this->repository->findOneById((string) Uuid::v7()));
    }

    public function test_normalized_registration_number_is_unique_in_database(): void
    {
        $this->repository->add($this->vehicle('C125CA77'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Автомобиль с таким госномером уже существует.');
        $this->repository->add($this->vehicle('c125ca77'));
    }

    public function test_changing_registration_number_cannot_duplicate_another_vehicle(): void
    {
        $first = $this->vehicle('E126EC77');
        $second = $this->vehicle('K127HH77');
        $this->repository->add($first);
        $this->repository->add($second);
        $this->em->clear();

        $loaded = $this->repository->findOneById($second->getId());
        self::assertNotNull($loaded);
        $loaded->changeRegistrationNumber(new RegistrationNumber('e126ec77'));

        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Автомобиль с таким госномером уже существует.');
        $this->repository->add($loaded);
    }

    public function test_other_unique_constraints_are_not_translated(): void
    {
        $id = Uuid::v7();
        $this->repository->add(new Vehicle($id, 'Первый', new RegistrationNumber('M128KK77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero()));
        $this->em->clear();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->repository->add(new Vehicle($id, 'Второй', new RegistrationNumber('H129MM77'), new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero()));
    }

    public function test_vehicle_can_be_loaded_for_update_inside_transaction(): void
    {
        $vehicle = $this->vehicle('O130PP77');
        $this->repository->add($vehicle);
        $this->em->clear();

        $loaded = $this->repository->findOneById($vehicle->getId(), forUpdate: true);

        self::assertNotNull($loaded);
        self::assertSame($vehicle->getId(), $loaded->getId());
    }

    public function test_filter_searches_model_and_registration_number_with_pagination(): void
    {
        $this->repository->add($this->vehicle('T901AA77', 'Поиск Альфа'));
        $this->repository->add($this->vehicle('T902AA77', 'Поиск Бета'));
        $this->repository->add($this->vehicle('O903AA77', 'Другая модель'));
        $this->em->clear();

        $first = $this->repository->findByFilter(new VehicleFilter('поиск', Pager::fromPage(1, 1)));
        $second = $this->repository->findByFilter(new VehicleFilter('поиск', Pager::fromPage(2, 1)));
        self::assertSame(2, $first->total);
        self::assertCount(1, $first->items);
        self::assertCount(1, $second->items);
        self::assertNotEquals($first->items[0], $second->items[0]);
        self::assertSame(1, $this->repository->findByFilter(new VehicleFilter('T901'))->total);
        self::assertSame(0, $this->repository->findByFilter(new VehicleFilter('%'))->total);
    }

    private function vehicle(string $registration, string $model = 'Модель', int $odometer = 0, string $fuel = '0', ?PositiveNumber $rate = null, ?\DateTimeImmutable $initialAt = null): Vehicle
    {
        return new Vehicle(Uuid::v7(), $model, new RegistrationNumber($registration), $initialAt ?? new \DateTimeImmutable('2026-01-01'), new Location('Россия', 'База'), $odometer, BigDecimal::of($fuel), $rate, FuelType::Ai95, FuelType::Ai95, FuelType::Ai98);
    }
}
