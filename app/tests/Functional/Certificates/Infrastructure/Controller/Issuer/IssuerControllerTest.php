<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Infrastructure\Controller\Issuer;

use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommand;
use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommandResult;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class IssuerControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private IssuerRepositoryInterface $repo;
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
        $this->repo = $container->get(IssuerRepositoryInterface::class);
        $this->commandBus = $container->get(CommandBusInterface::class);

        $this->userEmail = 'issuer_ctrl_'.uniqid('', true).'@example.com';
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
                $issuer = $em->find(Issuer::class, Uuid::fromString($id));
                if (null !== $issuer) {
                    $em->remove($issuer);
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
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    private function createIssuer(string $title): string
    {
        $result = $this->commandBus->execute(new CreateIssuerCommand($title));
        \assert($result instanceof CreateIssuerCommandResult);
        $this->createdIds[] = $result->id;

        return $result->id;
    }

    public function test_list_renders(): void
    {
        $this->client->request('GET', '/cabinet/certificate/issuer');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Издатели', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_form_renders(): void
    {
        $this->client->request('GET', '/cabinet/certificate/issuer/create');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Добавление издателя', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_persists_and_redirects_to_list(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $title = 'Контроллер Издатель-'.$suffix;

        $this->client->request('POST', '/cabinet/certificate/issuer/create', ['title' => $title]);

        self::assertResponseRedirects('/cabinet/certificate/issuer');

        $created = $this->repo->findOneByTitle($title);
        self::assertNotNull($created);
        $this->createdIds[] = $created->getId();
    }

    public function test_update_form_prefilled_and_renames(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createIssuer('Пере-имя-'.$suffix);

        $this->client->request('GET', '/cabinet/certificate/issuer/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Пере-имя-'.$suffix, (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/cabinet/certificate/issuer/'.$id.'/edit', ['title' => 'Новое-имя-'.$suffix]);
        self::assertResponseRedirects('/cabinet/certificate/issuer');

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame('Новое-имя-'.$suffix, $loaded->getTitle());
    }

    public function test_delete_removes_and_redirects(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = $this->createIssuer('Удаляемый-'.$suffix);

        $this->client->request('GET', '/cabinet/certificate/issuer/'.$id.'/delete');
        self::assertResponseRedirects('/cabinet/certificate/issuer');

        $this->em->clear();
        self::assertNull($this->repo->findOneById($id));
    }

    public function test_suggest_returns_json(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->createIssuer('Саджест-контроллер-'.$suffix);

        $this->client->request('GET', '/cabinet/certificate/issuer/suggest', ['q' => 'саджест-контроллер-'.$suffix]);

        self::assertResponseIsSuccessful();
        // Все JSON-ответы оборачиваются ResponseListener в {result,status,data,message} — читаем data.
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        $data = $payload['data'] ?? [];
        self::assertCount(1, $data);
        self::assertSame('Саджест-контроллер-'.$suffix, $data[0]['title']);
    }
}
