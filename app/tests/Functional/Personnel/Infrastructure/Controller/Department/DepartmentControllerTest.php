<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\ExtractsCsrfTokenTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-цикл дерева отделов: все мутации POST-only (create/edit/move/assign-head/delete)
 * — нет отдельной GET-страницы формы, добавление/правка идут модалками на дереве
 * (см. admin/personnel/department/index.html.twig).
 */
final class DepartmentControllerTest extends WebTestCase
{
    use ExtractsCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private DepartmentRepositoryInterface $repo;
    private CommandBusInterface $commandBus;
    private string $userEmail;
    private string $companyId;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(DepartmentRepositoryInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->companyId = UuidService::generate();

        $this->userEmail = 'department_ctrl_'.uniqid('', true).'@example.com';
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
            foreach (array_reverse($this->createdIds) as $id) {
                $department = $em->find(Department::class, $id);
                if (null !== $department) {
                    $em->remove($department);
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

    private function createDepartment(string $title, ?string $parentId = null): string
    {
        $result = $this->commandBus->execute(new CreateDepartmentCommand($this->companyId, $title, $parentId));
        \assert($result instanceof CreateDepartmentCommandResult);
        $this->createdIds[] = $result->id;

        return $result->id;
    }

    public function test_list_renders_tree_for_company(): void
    {
        $this->createDepartment('Контроллер-дерево-'.bin2hex(random_bytes(3)));

        $this->client->request('GET', '/cabinet/personnel/department', ['companyId' => $this->companyId]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Структура компании', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_via_post_persists_and_redirects_to_list(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $title = 'Контроллер Отдел-'.$suffix;

        $this->client->request('POST', '/cabinet/personnel/department/create', [
            'title' => $title,
            'companyId' => $this->companyId,
        ]);

        self::assertResponseRedirects('/cabinet/personnel/department?companyId='.$this->companyId);

        $found = array_values(array_filter(
            $this->repo->findByCompany($this->companyId),
            static fn (Department $d): bool => $d->getTitle() === $title,
        ));
        self::assertCount(1, $found);
        $this->createdIds[] = $found[0]->getId();
    }

    public function test_rename_via_post(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createDepartment('Старое-'.$suffix);
        $newTitle = 'Новое-'.$suffix;

        $this->client->request('POST', '/cabinet/personnel/department/'.$id.'/edit', [
            'title' => $newTitle,
            'companyId' => $this->companyId,
        ]);
        self::assertResponseRedirects('/cabinet/personnel/department?companyId='.$this->companyId);

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame($newTitle, $loaded->getTitle());
    }

    public function test_move_via_post(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $branchA = $this->createDepartment('Филиал А-'.$suffix);
        $branchB = $this->createDepartment('Филиал Б-'.$suffix);
        $node = $this->createDepartment('Отдел-'.$suffix, $branchA);

        $this->client->request('POST', '/cabinet/personnel/department/'.$node.'/move', [
            'parentId' => $branchB,
            'companyId' => $this->companyId,
        ]);
        self::assertResponseRedirects('/cabinet/personnel/department?companyId='.$this->companyId);

        $this->em->clear();
        $loaded = $this->repo->findOneById($node);
        self::assertNotNull($loaded);
        self::assertSame($branchB, $loaded->getParentId());
    }

    public function test_assign_head_via_post(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createDepartment('Отдел-'.$suffix);
        $headUlid = UuidService::generateUlid();

        $this->client->request('POST', '/cabinet/personnel/department/'.$id.'/assign-head', [
            'headUserUlid' => $headUlid,
            'companyId' => $this->companyId,
        ]);
        self::assertResponseRedirects('/cabinet/personnel/department?companyId='.$this->companyId);

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame($headUlid, $loaded->getHeadUserUlid());
    }

    public function test_delete_via_post_removes_and_redirects(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createDepartment('Удаляемый-'.$suffix);

        // companyId уходит query-строкой в URL (delete_modal собирает POST-URL из data-bs-url,
        // который уже несёт ?companyId=... — см. _node.html.twig).
        $token = $this->deleteCsrfToken($this->client);
        $this->client->request(
            'POST',
            '/cabinet/personnel/department/'.$id.'/delete?companyId='.$this->companyId,
            ['_token' => $token],
        );

        self::assertResponseRedirects('/cabinet/personnel/department?companyId='.$this->companyId);

        $this->em->clear();
        self::assertNull($this->repo->findOneById($id));
    }

    public function test_suggest_returns_json(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->createDepartment('Саджест-контроллер-'.$suffix);

        $this->client->request('GET', '/cabinet/personnel/department/suggest', ['q' => 'саджест-контроллер-'.$suffix]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? [];
        self::assertCount(1, $data['items'] ?? []);
        self::assertSame('Саджест-контроллер-'.$suffix, $data['items'][0]['title']);
    }

    public function test_by_ids_returns_json(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createDepartment('Байдис-'.$suffix);

        $this->client->request('GET', '/cabinet/personnel/department/by-ids', ['ids' => [$id]]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? $payload;
        self::assertCount(1, $data['items'] ?? []);
        self::assertSame($id, $data['items'][0]['id']);
    }
}
