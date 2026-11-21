<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Application\UseCase;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\ValueObject\Email;
use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Waybills\Application\UseCase\Command\CreateDriverProfile\CreateDriverProfileCommand;
use App\Waybills\Application\UseCase\Command\CreateDriverProfile\CreateDriverProfileCommandResult;
use App\Waybills\Application\UseCase\Command\UpdateDriverProfile\UpdateDriverProfileCommand;
use App\Waybills\Application\UseCase\Query\GetOwnDriverProfile\GetOwnDriverProfileQuery;
use App\Waybills\Application\UseCase\Query\GetOwnDriverProfile\GetOwnDriverProfileQueryResult;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

final class DriverProfileUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private EntityManagerInterface $em;
    private CommandBusInterface $commands;
    private QueryBusInterface $queries;
    private DriverProfileRepositoryInterface $profiles;
    private string $owner;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->commands = $container->get(CommandBusInterface::class);
        $this->queries = $container->get(QueryBusInterface::class);
        $this->profiles = $container->get(DriverProfileRepositoryInterface::class);
        $this->owner = (string) Uuid::v7();
        $this->authenticateAs($this->owner);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        $connection->executeStatement('DELETE FROM waybills_driver_profile WHERE owner_id = ?', [$this->owner]);
        $connection->executeStatement('DELETE FROM waybills_driver_profile WHERE owner_id = ?', [$this->owner.'-other']);
        parent::tearDown();
    }

    public function test_create_query_refresh_and_update_round_trip(): void
    {
        $result = $this->create();
        $this->em->clear();
        $profile = $this->profiles->findOneById($result->id);
        self::assertNotNull($profile);
        $this->em->refresh($profile);
        self::assertSame($this->owner, $profile->getOwnerId());
        self::assertSame('2020-02-01T12:34:56.123456+03:00', $profile->getDriverDetails()->licenseIssuedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('Водитель', $profile->getDriverDetails()->fullName);
        self::assertSame('1234567890', $profile->getDriverDetails()->licenseNumber->value());
        self::assertSame('11223344595', $profile->getDriverDetails()->snils->value());
        self::assertSame('База', $profile->getBase()->name);
        self::assertSame(10, $profile->getDestinations()[0]->distance);
        self::assertSame('Предприятие', $profile->getDestinations()[0]->enterprise);

        $query = $this->queries->execute(new GetOwnDriverProfileQuery());
        self::assertInstanceOf(GetOwnDriverProfileQueryResult::class, $query);
        self::assertSame($result->id, $query->id);
        self::assertSame('Клиент', $query->routeSettings?->destinations()[0]->location->name);

        // Новая база была старым направлением, а старая база становится направлением.
        $route = new RouteSettings(new Location('Клиент'), new Destination(new Location('База'), 7));
        $this->commands->execute(new UpdateDriverProfileCommand(Uuid::fromString($result->id), $this->details('Новое имя'), $route));
        $this->em->clear();
        $updated = $this->profiles->findOneById($result->id);
        self::assertNotNull($updated);
        self::assertSame('Новое имя', $updated->getDriverDetails()->fullName);
        self::assertSame('Клиент', $updated->getBase()->name);
        self::assertSame('База', $updated->getDestinations()[0]->location->name);
        self::assertSame(7, $updated->getDestinations()[0]->distance);
    }

    public function test_other_user_is_forbidden_and_sees_only_own_profile_manager_can_edit(): void
    {
        $result = $this->create();
        $this->authenticateAs('other-'.Uuid::v7());
        $query = $this->queries->execute(new GetOwnDriverProfileQuery());
        self::assertInstanceOf(GetOwnDriverProfileQueryResult::class, $query);
        self::assertNull($query->id);
        try {
            $this->commands->execute(new UpdateDriverProfileCommand(Uuid::fromString($result->id), $this->details('Чужой'), $this->route()));
            self::fail('Редактирование чужой карточки должно быть запрещено.');
        } catch (ForbiddenException) {
        }
        $this->authenticateAs('admin-'.Uuid::v7(), ['ROLE_ADMIN']);
        $this->commands->execute(new UpdateDriverProfileCommand(Uuid::fromString($result->id), $this->details('Менеджер'), $this->route()));
        $this->em->clear();
        $profile = $this->profiles->findOneById($result->id);
        self::assertNotNull($profile);
        self::assertSame($this->owner, $profile->getOwnerId());
        self::assertSame('Менеджер', $profile->getDriverDetails()->fullName);
        $this->authenticateAsSystem();
        $this->commands->execute(new UpdateDriverProfileCommand(Uuid::fromString($result->id), $this->details('Система'), $this->route()));
        $this->em->clear();
        self::assertSame('Система', $this->profiles->findOneById($result->id)?->getDriverDetails()->fullName);
    }

    public function test_second_profile_is_rejected_by_database(): void
    {
        $this->create();
        $this->expectException(AppException::class);
        $this->expectExceptionMessage('Карточка водителя для этого пользователя уже существует.');
        $this->create();
    }

    public function test_different_owners_can_create_profiles_without_a_vehicle(): void
    {
        $first = $this->create();
        $this->authenticateAs($this->owner.'-other');
        $second = $this->create();
        $this->em->clear();
        $query = $this->queries->execute(new GetOwnDriverProfileQuery());
        self::assertInstanceOf(GetOwnDriverProfileQueryResult::class, $query);
        self::assertSame($second->id, $query->id);
        self::assertNotSame($first->id, $second->id);
        self::assertSame($this->owner.'-other', $this->profiles->findOneById($second->id)?->getOwnerId());
    }

    public function test_update_without_vehicle_preserves_owner(): void
    {
        $result = $this->create();
        $this->commands->execute(new UpdateDriverProfileCommand(Uuid::fromString($result->id), $this->details('Изменено'), $this->route()));
        $this->em->clear();
        $profile = $this->profiles->findOneById($result->id);
        self::assertNotNull($profile);
        self::assertSame('Изменено', $profile->getDriverDetails()->fullName);
        self::assertSame($this->owner, $profile->getOwnerId());
    }

    public function test_create_without_vehicle_persists_route(): void
    {
        $result = $this->create();
        $this->em->clear();
        self::assertSame('База', $this->profiles->findOneById($result->id)?->getBase()->name);
    }

    public function test_profile_can_be_saved_without_a_vehicle(): void
    {
        $route = $this->route();
        $profile = new DriverProfile(Uuid::v7(), $this->owner, $this->details(), $route->base, ...$route->destinations());
        $this->profiles->add($profile);
        $this->em->clear();
        self::assertSame($this->owner, $this->profiles->findOneById($profile->getId())?->getOwnerId());
    }

    private function create(): CreateDriverProfileCommandResult
    {
        $result = $this->commands->execute(new CreateDriverProfileCommand($this->details(), $this->route()));
        self::assertInstanceOf(CreateDriverProfileCommandResult::class, $result);

        return $result;
    }

    private function details(string $name = 'Водитель'): DriverDetails
    {
        return new DriverDetails($name, '1234567890', new \DateTimeImmutable('2020-02-01T12:34:56.123456+03:00'), '11223344595');
    }

    private function route(): RouteSettings
    {
        return new RouteSettings(new Location('База'), new Destination(new Location('Клиент'), 10, 'Предприятие'));
    }

    /** @param list<string> $roles */
    private function authenticateAs(string $id, array $roles = ['ROLE_USER']): void
    {
        $user = new class($id, $roles) implements AuthUserInterface {
            /** @param list<string> $roles */
            public function __construct(private string $id, private array $roles)
            {
            }

            public function getUlid(): string
            {
                return $this->id;
            }

            public function getEmail(): Email
            {
                return new Email('test@example.com');
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function getPassword(): ?string
            {
                return null;
            }

            public function getUserIdentifier(): string
            {
                return $this->id;
            }

            public function eraseCredentials(): void
            {
            }
        };
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new PreAuthenticatedToken($user, 'test', $user->getRoles()));
    }
}
