<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Domain\Aggregate\Position\Position;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Support\ExtractsCsrfTokenTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PositionControllerTest extends WebTestCase
{
    use ExtractsCsrfTokenTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private PositionRepositoryInterface $repo;
    private CommandBusInterface $commandBus;
    private string $userEmail;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(PositionRepositoryInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);

        $this->userEmail = 'position_ctrl_'.uniqid('', true).'@example.com';
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
            foreach ($this->createdIds as $id) {
                $position = $em->find(Position::class, $id);
                if (null !== $position) {
                    $em->remove($position);
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
        $this->createdIds[] = $result->id;

        return $result->id;
    }

    public function test_list_renders(): void
    {
        $this->client->request('GET', '/cabinet/personnel/position');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Должности', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_form_renders(): void
    {
        $this->client->request('GET', '/cabinet/personnel/position/create');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Добавление должности', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_persists_and_redirects_to_list(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $title = 'Контроллер Должность-'.$suffix;

        $this->client->request('POST', '/cabinet/personnel/position/create', ['title' => $title]);

        self::assertResponseRedirects('/cabinet/personnel/position');

        $created = $this->repo->findOneByTitle($title);
        self::assertNotNull($created);
        $this->createdIds[] = $created->getId();
    }

    public function test_update_form_prefilled_and_renames(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPosition('Пере-имя-'.$suffix);

        $this->client->request('GET', '/cabinet/personnel/position/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Пере-имя-'.$suffix, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/cabinet/personnel/position/'.$id.'/edit', ['title' => 'Новое-имя-'.$suffix]);
        self::assertResponseRedirects('/cabinet/personnel/position');

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame('Новое-имя-'.$suffix, $loaded->getTitle());
    }

    public function test_delete_removes_and_redirects(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPosition('Удаляемая-'.$suffix);

        $token = $this->deleteCsrfToken($this->client);
        $this->client->request('POST', '/cabinet/personnel/position/'.$id.'/delete', ['_token' => $token]);
        self::assertResponseRedirects('/cabinet/personnel/position');

        $this->em->clear();
        self::assertNull($this->repo->findOneById($id));
    }

    public function test_suggest_returns_json(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->createPosition('Саджест-контроллер-'.$suffix);

        $this->client->request('GET', '/cabinet/personnel/position/suggest', ['q' => 'саджест-контроллер-'.$suffix]);

        self::assertResponseIsSuccessful();
        // Все JSON-ответы оборачиваются ResponseListener в {result,status,data,message} — читаем data.
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? [];
        self::assertCount(1, $data['items'] ?? []);
        self::assertSame('Саджест-контроллер-'.$suffix, $data['items'][0]['title']);
    }

    public function test_quick_create_returns_created_json(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $title = 'Квик-'.$suffix;

        $this->client->request(
            'POST',
            '/cabinet/personnel/position/quick',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );

        self::assertResponseStatusCodeSame(201);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? $payload;
        self::assertSame($title, $data['title']);
        $this->createdIds[] = $data['id'];
    }

    public function test_quick_create_duplicate_returns_error_json_not_500(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $title = 'Квик-дубль-'.$suffix;
        $this->createPosition($title);

        $this->client->request(
            'POST',
            '/cabinet/personnel/position/quick',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );

        self::assertResponseStatusCodeSame(422);
        // Статусы >=400 ResponseListener оборачивает как {result:'error', data:null, message}
        // (не {data:{message}}, см. ResponseDTOTransformer::buildResponseData).
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('message', $payload);
        self::assertNotEmpty($payload['message']);
    }

    public function test_by_ids_returns_json(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createPosition('Байдис-'.$suffix);

        $this->client->request('GET', '/cabinet/personnel/position/by-ids', ['ids' => [$id]]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? $payload;
        self::assertCount(1, $data['items'] ?? []);
        self::assertSame($id, $data['items'][0]['id']);
    }
}
