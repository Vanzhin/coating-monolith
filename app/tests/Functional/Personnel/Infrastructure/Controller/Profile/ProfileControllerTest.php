<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Infrastructure\Controller\Profile;

use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Personnel\Application\UseCase\Command\DeleteDepartment\DeleteDepartmentCommand;
use App\Personnel\Application\UseCase\Command\DeletePosition\DeletePositionCommand;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Reports\Application\UseCase\Command\DeleteCounterparty\DeleteCounterpartyCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\ExtractsCsrfTokenTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Зеркалит PositionControllerTest: тонкий контроллер, гейт — в хендлере (T8), тут только
 * HTTP-контракт (форма/редирект/ре-рендер с ошибкой).
 */
final class ProfileControllerTest extends WebTestCase
{
    use ExtractsCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ProfileRepositoryInterface $repo;
    private CommandBusInterface $commandBus;
    private string $userEmail;

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
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(ProfileRepositoryInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);

        $this->userEmail = 'profile_ctrl_'.uniqid('', true).'@example.com';
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
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
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if (null !== $user) {
                $em->remove($user);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setValue($obj, $value);
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

    /** @return array{position: string, organization: string, department: string} */
    private function makeFixtures(): array
    {
        $suffix = strtr(bin2hex(random_bytes(3)), '0123456789', 'abcdefghij');
        $organizationId = $this->createOrganization('Контроллер-Орг-'.$suffix);
        $positionId = $this->createPosition('Контроллер-Должность-'.$suffix);
        $departmentId = $this->createDepartment('Контроллер-Отдел-'.$suffix, $organizationId);

        return ['position' => $positionId, 'organization' => $organizationId, 'department' => $departmentId];
    }

    /** @param array{position: string, organization: string, department: string} $fixtures */
    private function createProfile(array $fixtures, ?string $lastName = null): string
    {
        $result = $this->commandBus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: $lastName ?? 'Иванов',
            firstName: 'Иван',
            middleName: null,
            positionId: $fixtures['position'],
            organizationId: $fixtures['organization'],
            departmentId: $fixtures['department'],
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
        \assert($result instanceof CreateProfileCommandResult);
        $this->createdProfileIds[] = $result->id;

        return $result->id;
    }

    public function test_list_renders(): void
    {
        $this->client->request('GET', '/cabinet/personnel/profile');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Персонал', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_form_renders(): void
    {
        $this->client->request('GET', '/cabinet/personnel/profile/create');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Добавление профиля', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_persists_and_redirects_to_list(): void
    {
        $fixtures = $this->makeFixtures();
        $suffix = strtr(bin2hex(random_bytes(3)), '0123456789', 'abcdefghij');
        $userUlid = UuidService::generateUlid();

        $this->client->request('POST', '/cabinet/personnel/profile/create', [
            'userUlid' => $userUlid,
            'lastName' => 'Контроллер-Фамилия-'.$suffix,
            'firstName' => 'Иван',
            'middleName' => 'Иванович',
            'positionId' => $fixtures['position'],
            'organizationId' => $fixtures['organization'],
            'departmentId' => $fixtures['department'],
            'personnelNumber' => 'PN-'.$suffix,
            'hiredAt' => '2024-05-01',
            'clothing' => '52',
            'gender' => 'male',
        ]);

        self::assertResponseRedirects('/cabinet/personnel/profile');

        $this->em->clear();
        $created = $this->repo->findOneByUserUlid($userUlid);
        self::assertNotNull($created);
        self::assertSame('Контроллер-Фамилия-'.$suffix, $created->getFullName()->lastName->value);
        $this->createdProfileIds[] = $created->getId();
    }

    public function test_create_with_empty_last_name_rerenders_with_error(): void
    {
        $fixtures = $this->makeFixtures();

        $this->client->request('POST', '/cabinet/personnel/profile/create', [
            'userUlid' => UuidService::generateUlid(),
            'lastName' => '',
            'firstName' => 'Иван',
            'positionId' => $fixtures['position'],
            'organizationId' => $fixtures['organization'],
            'departmentId' => $fixtures['department'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Фамилия и имя обязательны.', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_with_malformed_hired_at_rerenders_with_error(): void
    {
        $fixtures = $this->makeFixtures();

        $this->client->request('POST', '/cabinet/personnel/profile/create', [
            'userUlid' => UuidService::generateUlid(),
            'lastName' => 'Иванов',
            'firstName' => 'Иван',
            'positionId' => $fixtures['position'],
            'organizationId' => $fixtures['organization'],
            'departmentId' => $fixtures['department'],
            'hiredAt' => 'не дата',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Некорректная дата приёма.', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_with_department_of_other_organization_rerenders_with_error(): void
    {
        $suffix = strtr(bin2hex(random_bytes(3)), '0123456789', 'abcdefghij');
        $organizationAId = $this->createOrganization('Контроллер-Орг-А-'.$suffix);
        $organizationBId = $this->createOrganization('Контроллер-Орг-Б-'.$suffix);
        $positionId = $this->createPosition('Контроллер-Должность-'.$suffix);
        $departmentOfB = $this->createDepartment('Контроллер-Отдел-Б-'.$suffix, $organizationBId);

        $this->client->request('POST', '/cabinet/personnel/profile/create', [
            'userUlid' => UuidService::generateUlid(),
            'lastName' => 'Иванов',
            'firstName' => 'Иван',
            'positionId' => $positionId,
            'organizationId' => $organizationAId,
            'departmentId' => $departmentOfB,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('не принадлежит выбранной организации', (string) $this->client->getResponse()->getContent());
    }

    public function test_update_form_prefilled_and_renames(): void
    {
        $fixtures = $this->makeFixtures();
        $suffix = strtr(bin2hex(random_bytes(3)), '0123456789', 'abcdefghij');
        $id = $this->createProfile($fixtures, 'Пере-имя-'.$suffix);

        $this->client->request('GET', '/cabinet/personnel/profile/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Пере-имя-'.$suffix, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/cabinet/personnel/profile/'.$id.'/edit', [
            'lastName' => 'Новое-имя-'.$suffix,
            'firstName' => 'Иван',
            'positionId' => $fixtures['position'],
            'organizationId' => $fixtures['organization'],
            'departmentId' => $fixtures['department'],
        ]);
        self::assertResponseRedirects('/cabinet/personnel/profile');

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame('Новое-имя-'.$suffix, $loaded->getFullName()->lastName->value);
    }

    public function test_delete_removes_and_redirects(): void
    {
        $fixtures = $this->makeFixtures();
        $id = $this->createProfile($fixtures);

        $token = $this->deleteCsrfToken($this->client);
        $this->client->request('POST', '/cabinet/personnel/profile/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/cabinet/personnel/profile');

        $this->em->clear();
        self::assertNull($this->repo->findOneById($id));
    }
}
