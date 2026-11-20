<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\ValueObject\Email;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\UseCase\Command\CreateVehicle\CreateVehicleCommand;
use App\Waybills\Application\UseCase\Command\UpdateVehicle\UpdateVehicleCommand;
use App\Waybills\Application\UseCase\Query\GetPagedVehicles\GetPagedVehiclesQuery;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Repository\VehicleFilter;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final class VehicleOperationsTest extends KernelTestCase
{
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    private VehicleRepositoryInterface $vehicles;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commands = $container->get(CommandBusInterface::class);
        $this->queries = $container->get(QueryBusInterface::class);
        $this->vehicles = $container->get(VehicleRepositoryInterface::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->authenticateAs('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
        parent::tearDown();
    }

    public function test_user_creates_and_updates_vehicle_without_losing_state(): void
    {
        $id = $this->commands->execute($this->createVehicle('Модель', 'A123BC77', new PositiveNumber(8)));
        self::assertIsString($id);
        self::assertTrue(Uuid::isValid($id));

        $this->commands->execute(new UpdateVehicleCommand($id, 'Новая модель', 'B124CC77', null, FuelType::Ai98, FuelType::Ai98));
        $this->em->clear();

        $vehicle = $this->vehicles->findOneById($id);
        self::assertNotNull($vehicle);
        self::assertSame('Новая модель', $vehicle->getModel());
        self::assertSame('b124cc77', $vehicle->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai98], $vehicle->getFuelTypes());
        self::assertSame(FuelType::Ai98, $vehicle->getDefaultFuelType());
        self::assertNull($vehicle->getConsumptionRate());
        self::assertSame(100, $vehicle->currentOdometer());
        self::assertSame('10', (string) $vehicle->fuelRemaining());
    }

    public function test_duplicate_registration_is_reported_on_create(): void
    {
        $this->commands->execute($this->createVehicle('Первая', 'C125CA77'));
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Автомобиль с таким госномером уже существует.');
        $this->commands->execute($this->createVehicle('Вторая', 'c125ca77'));
    }

    public function test_duplicate_registration_is_reported_on_update(): void
    {
        $this->commands->execute($this->createVehicle('Первая', 'E126EC77'));
        $id = $this->commands->execute($this->createVehicle('Вторая', 'K127HH77'));
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Автомобиль с таким госномером уже существует.');
        $this->commands->execute(new UpdateVehicleCommand($id, 'Вторая', 'e126ec77', null, FuelType::Ai95, FuelType::Ai95));
    }

    public function test_query_bus_paginates_search(): void
    {
        $this->commands->execute($this->createVehicle('Тест Альфа', 'M128KK77'));
        $this->commands->execute($this->createVehicle('Тест Бета', 'H129MM77'));
        $result = $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter('тест', Pager::fromPage(2, 1))));
        self::assertInstanceOf(PaginationResult::class, $result);
        self::assertSame(2, $result->total);
        self::assertCount(1, $result->items);
    }

    #[DataProvider('operations')]
    public function test_anonymous_user_cannot_access_any_operation(string $operation): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(null);
        $id = (string) Uuid::v7();
        $this->expectException(ForbiddenException::class);
        match ($operation) {
            'create' => $this->commands->execute($this->createVehicle('Модель', 'O130PP77')),
            'update' => $this->commands->execute(new UpdateVehicleCommand($id, 'Модель', 'O130PP77', null, FuelType::Ai95, FuelType::Ai95)),
            'query' => $this->queries->execute(new GetPagedVehiclesQuery(new VehicleFilter())),
            default => throw new \LogicException('Неизвестная операция.'),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        foreach (['create', 'update', 'query'] as $operation) {
            yield $operation => [$operation];
        }
    }

    private function createVehicle(string $model, string $number, ?PositiveNumber $rate = null): CreateVehicleCommand
    {
        return new CreateVehicleCommand(
            $model, $number, new \DateTimeImmutable('2026-01-01'),
            'Россия', 'База', '', '', 100, BigDecimal::of(10), $rate, FuelType::Ai95, FuelType::Ai95,
        );
    }

    private function authenticateAs(string $id): void
    {
        $user = new class($id) implements AuthUserInterface {
            public function __construct(private string $id)
            {
            }

            public function getUlid(): string
            {
                return $this->id;
            }

            public function getEmail(): Email
            {
                return new Email('user@example.com');
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getPassword(): ?string
            {
                return null;
            }

            public function getUserIdentifier(): string
            {
                return $this->getUlid();
            }

            public function eraseCredentials(): void
            {
            }
        };
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new PreAuthenticatedToken($user, 'test', $user->getRoles()));
    }
}
