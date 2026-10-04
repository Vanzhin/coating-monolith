<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Application\UseCase;

use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Personnel\Application\UseCase\Command\DeleteDepartment\DeleteDepartmentCommand;
use App\Personnel\Application\UseCase\Command\DeletePosition\DeletePositionCommand;
use App\Personnel\Application\UseCase\Command\DeleteProfile\DeleteProfileCommand;
use App\Personnel\Application\UseCase\Command\UpdateProfile\UpdateProfileCommand;
use App\Personnel\Application\UseCase\Query\GetPagedProfiles\GetPagedProfilesQuery;
use App\Personnel\Application\UseCase\Query\GetPagedProfiles\GetPagedProfilesQueryResult;
use App\Personnel\Application\UseCase\Query\GetProfileByUserUlid\GetProfileByUserUlidQuery;
use App\Personnel\Application\UseCase\Query\GetProfileByUserUlid\GetProfileByUserUlidQueryResult;
use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Reports\Application\UseCase\Command\DeleteCounterparty\DeleteCounterpartyCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Профиль сотрудника через Application-слой: create резолвит снапшоты должности/организации/отдела
 * (title подтягивается на момент выбора), связка «отдел↔организация» проверяется при create И update
 * (пересобирается тем же ProfileMaker), gender валидируется через tryFrom (закрывает T1 follow-up).
 */
final class ProfileUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ProfileRepositoryInterface $repo;
    /** @var list<string> */
    private array $createdProfileIds = [];
    /** @var list<string> */
    private array $createdPositionIds = [];
    /** @var list<string> */
    private array $createdDepartmentIds = [];
    /** @var list<string> */
    private array $createdCounterpartyIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->repo = $container->get(ProfileRepositoryInterface::class);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $this->authenticateAsSystem();
        try {
            // Профили — первыми: должность/отдел с «занятыми» профилями удалить нельзя (guard в их хендлерах).
            foreach ($this->createdProfileIds as $id) {
                $profile = $this->repo->findOneById($id);
                if (null !== $profile) {
                    $this->repo->remove($profile);
                }
            }
            foreach ($this->createdDepartmentIds as $id) {
                try {
                    $this->commandBus->execute(new DeleteDepartmentCommand($id));
                } catch (AppException) {
                    // уже удалён/не найден — не мешаем очистке остальных фикстур
                }
            }
            foreach ($this->createdPositionIds as $id) {
                try {
                    $this->commandBus->execute(new DeletePositionCommand($id));
                } catch (AppException) {
                }
            }
            foreach ($this->createdCounterpartyIds as $id) {
                try {
                    $this->commandBus->execute(new DeleteCounterpartyCommand($id));
                } catch (AppException) {
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function createProfile(CreateProfileCommand $command): CreateProfileCommandResult
    {
        $result = $this->commandBus->execute($command);
        \assert($result instanceof CreateProfileCommandResult);
        $this->createdProfileIds[] = $result->id;

        return $result;
    }

    private function createPosition(string $title): string
    {
        $result = $this->commandBus->execute(new CreatePositionCommand($title));
        \assert($result instanceof CreatePositionCommandResult);
        $this->createdPositionIds[] = $result->id;

        return $result->id;
    }

    private function createOrganization(string $title): string
    {
        $result = $this->commandBus->execute(new CreateCounterpartyCommand($title, $this->randomTin()));
        \assert($result instanceof CreateCounterpartyCommandResult);
        $this->createdCounterpartyIds[] = $result->id;

        return $result->id;
    }

    private function createDepartment(string $title, string $companyId): string
    {
        $result = $this->commandBus->execute(new CreateDepartmentCommand($companyId, $title));
        \assert($result instanceof CreateDepartmentCommandResult);
        $this->createdDepartmentIds[] = $result->id;

        return $result->id;
    }

    /** Валидный по контрольной сумме ИНН (10 цифр) — Counterparty его требует. */
    private function randomTin(): string
    {
        $digits = '';
        for ($i = 0; $i < 9; ++$i) {
            $digits .= random_int(0, 9);
        }
        $weights = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $digits[$i];
        }

        return $digits.($sum % 11 % 10);
    }

    private function makeCreateCommand(
        string $userUlid,
        string $positionId,
        string $organizationId,
        string $departmentId,
        ?string $gender = null,
    ): CreateProfileCommand {
        return new CreateProfileCommand(
            userUlid: $userUlid,
            lastName: 'Иванов',
            firstName: 'Иван',
            middleName: 'Иванович',
            positionId: $positionId,
            organizationId: $organizationId,
            departmentId: $departmentId,
            personnelNumber: 'PN-'.uniqid('', true),
            hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52',
            shoes: '42',
            headgear: '56',
            respirator: null,
            gloves: '9',
            height: '176',
            gender: $gender,
        );
    }

    public function test_create_persists_snapshots_with_titles(): void
    {
        $positionTitle = 'Маляр '.uniqid('', true);
        $positionId = $this->createPosition($positionTitle);
        $orgTitle = 'ООО Ромашка '.uniqid('', true);
        $organizationId = $this->createOrganization($orgTitle);
        $deptTitle = 'Цех №1 '.uniqid('', true);
        $departmentId = $this->createDepartment($deptTitle, $organizationId);
        $userUlid = UuidService::generateUlid();

        $created = $this->createProfile(
            $this->makeCreateCommand($userUlid, $positionId, $organizationId, $departmentId, Gender::Male->value),
        );

        $loaded = $this->repo->findOneById($created->id);
        self::assertNotNull($loaded);
        self::assertSame($userUlid, $loaded->getUserUlid());
        self::assertSame($positionId, $loaded->getPosition()->id);
        self::assertSame($positionTitle, $loaded->getPosition()->title);
        self::assertSame($organizationId, $loaded->getOrganization()->id);
        self::assertSame($orgTitle, $loaded->getOrganization()->title);
        self::assertSame($departmentId, $loaded->getDepartment()->id);
        self::assertSame($deptTitle, $loaded->getDepartment()->title);
        self::assertSame(Gender::Male, $loaded->getSizes()->gender);
    }

    public function test_create_with_department_of_other_organization_throws(): void
    {
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $organizationAId = $this->createOrganization('Орг А '.uniqid('', true));
        $organizationBId = $this->createOrganization('Орг Б '.uniqid('', true));
        $departmentOfB = $this->createDepartment('Отдел Б '.uniqid('', true), $organizationBId);

        $this->expectException(AppException::class);
        $this->commandBus->execute(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionId, $organizationAId, $departmentOfB),
        );
    }

    public function test_create_with_invalid_gender_throws(): void
    {
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $departmentId = $this->createDepartment('Отдел '.uniqid('', true), $organizationId);

        $this->expectException(AppException::class);
        $this->commandBus->execute(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionId, $organizationId, $departmentId, 'alien'),
        );
    }

    public function test_update_changes_position_and_department(): void
    {
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $positionOldId = $this->createPosition('Старая должность '.uniqid('', true));
        $positionNewId = $this->createPosition('Новая должность '.uniqid('', true));
        $departmentOldId = $this->createDepartment('Старый отдел '.uniqid('', true), $organizationId);
        $departmentNewId = $this->createDepartment('Новый отдел '.uniqid('', true), $organizationId);

        $created = $this->createProfile(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionOldId, $organizationId, $departmentOldId),
        );

        $this->commandBus->execute(new UpdateProfileCommand(
            id: $created->id,
            lastName: 'Петров',
            firstName: 'Пётр',
            middleName: null,
            positionId: $positionNewId,
            organizationId: $organizationId,
            departmentId: $departmentNewId,
            personnelNumber: null,
            hiredAt: null,
            clothing: null,
            shoes: null,
            headgear: null,
            respirator: null,
            gloves: null,
            height: null,
            gender: null,
        ));

        $loaded = $this->repo->findOneById($created->id);
        self::assertNotNull($loaded);
        self::assertSame($positionNewId, $loaded->getPosition()->id);
        self::assertSame($departmentNewId, $loaded->getDepartment()->id);
        self::assertSame('Петров', $loaded->getFullName()->lastName->value);
    }

    public function test_update_to_department_of_other_organization_throws(): void
    {
        $organizationAId = $this->createOrganization('Орг А '.uniqid('', true));
        $organizationBId = $this->createOrganization('Орг Б '.uniqid('', true));
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $departmentOfA = $this->createDepartment('Отдел А '.uniqid('', true), $organizationAId);
        $departmentOfB = $this->createDepartment('Отдел Б '.uniqid('', true), $organizationBId);

        $created = $this->createProfile(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionId, $organizationAId, $departmentOfA),
        );

        $this->expectException(AppException::class);
        $this->commandBus->execute(new UpdateProfileCommand(
            id: $created->id,
            lastName: 'Иванов',
            firstName: 'Иван',
            middleName: null,
            positionId: $positionId,
            organizationId: $organizationAId,
            departmentId: $departmentOfB,
            personnelNumber: null,
            hiredAt: null,
            clothing: null,
            shoes: null,
            headgear: null,
            respirator: null,
            gloves: null,
            height: null,
            gender: null,
        ));
    }

    public function test_update_missing_throws_not_found(): void
    {
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $departmentId = $this->createDepartment('Отдел '.uniqid('', true), $organizationId);

        $this->expectException(AppException::class);
        $this->commandBus->execute(new UpdateProfileCommand(
            id: '00000000-0000-0000-0000-000000000000',
            lastName: 'Иванов',
            firstName: 'Иван',
            middleName: null,
            positionId: $positionId,
            organizationId: $organizationId,
            departmentId: $departmentId,
            personnelNumber: null,
            hiredAt: null,
            clothing: null,
            shoes: null,
            headgear: null,
            respirator: null,
            gloves: null,
            height: null,
            gender: null,
        ));
    }

    public function test_delete_removes(): void
    {
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $departmentId = $this->createDepartment('Отдел '.uniqid('', true), $organizationId);
        $created = $this->createProfile(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionId, $organizationId, $departmentId),
        );

        $this->commandBus->execute(new DeleteProfileCommand($created->id));

        self::assertNull($this->repo->findOneById($created->id));
    }

    public function test_delete_missing_throws_not_found(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new DeleteProfileCommand('00000000-0000-0000-0000-000000000000'));
    }

    public function test_regular_user_cannot_create(): void
    {
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $departmentId = $this->createDepartment('Отдел '.uniqid('', true), $organizationId);

        // Не персистим — токену для проверки ролей персистентность не нужна, только roles().
        $user = new User(new Email('profile_'.bin2hex(random_bytes(4)).'@example.com'));
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken($user, 'test', $user->getRoles()),
        );

        $this->expectException(ForbiddenException::class);
        $this->commandBus->execute(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionId, $organizationId, $departmentId),
        );
    }

    public function test_get_by_user_ulid_returns_profile(): void
    {
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $departmentId = $this->createDepartment('Отдел '.uniqid('', true), $organizationId);
        $userUlid = UuidService::generateUlid();
        $created = $this->createProfile(
            $this->makeCreateCommand($userUlid, $positionId, $organizationId, $departmentId),
        );

        $result = $this->queryBus->execute(new GetProfileByUserUlidQuery($userUlid));
        \assert($result instanceof GetProfileByUserUlidQueryResult);

        self::assertNotNull($result->profile);
        self::assertSame($created->id, $result->profile->id);
    }

    public function test_get_by_user_ulid_returns_null_when_missing(): void
    {
        $result = $this->queryBus->execute(new GetProfileByUserUlidQuery(UuidService::generateUlid()));
        \assert($result instanceof GetProfileByUserUlidQueryResult);

        self::assertNull($result->profile);
    }

    public function test_paged_list_filters_by_full_name(): void
    {
        $organizationId = $this->createOrganization('Орг '.uniqid('', true));
        $positionId = $this->createPosition('Маляр '.uniqid('', true));
        $departmentId = $this->createDepartment('Отдел '.uniqid('', true), $organizationId);
        $suffix = strtr(bin2hex(random_bytes(3)), '0123456789', 'abcdefghij');

        $matching = $this->createProfile(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Уникальнов-'.$suffix,
            firstName: 'Иван',
            middleName: null,
            positionId: $positionId,
            organizationId: $organizationId,
            departmentId: $departmentId,
            personnelNumber: null,
            hiredAt: null,
            clothing: null,
            shoes: null,
            headgear: null,
            respirator: null,
            gloves: null,
            height: null,
            gender: null,
        ));
        $other = $this->createProfile(
            $this->makeCreateCommand(UuidService::generateUlid(), $positionId, $organizationId, $departmentId),
        );

        $result = $this->queryBus->execute(new GetPagedProfilesQuery(
            new ProfilesFilter(pager: Pager::fromPage(1, 50), search: 'Уникальнов-'.$suffix),
        ));
        \assert($result instanceof GetPagedProfilesQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->profiles);
        self::assertContains($matching->id, $ids);
        self::assertNotContains($other->id, $ids);
    }
}
