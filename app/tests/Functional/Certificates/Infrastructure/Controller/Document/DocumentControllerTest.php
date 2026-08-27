<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Command\CreateDocument\CreateDocumentCommand;
use App\Certificates\Application\UseCase\Command\CreateDocument\CreateDocumentCommandResult;
use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Certificates\Infrastructure\Storage\DocumentFileStorage;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class DocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private DocumentRepositoryInterface $repo;
    private CommandBusInterface $commandBus;
    private DocumentFileStorage $storage;
    private IssuerSpecification $issuerSpec;
    private string $userEmail;
    private ?string $viewerEmail = null;
    private string $issuerId;

    /** @var list<string> */
    private array $createdDocIds = [];
    /** @var list<string> */
    private array $writtenFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->repo = $c->get(DocumentRepositoryInterface::class);
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->storage = $c->get(DocumentFileStorage::class);
        $this->issuerSpec = $c->get(IssuerSpecification::class);

        $this->userEmail = 'doc_ctrl_'.uniqid('', true).'@example.com';
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($user);

        $issuerUuid = Uuid::v7();
        $this->em->persist(new Issuer($issuerUuid, 'Изд-'.bin2hex(random_bytes(3)), $this->issuerSpec));
        $this->issuerId = (string) $issuerUuid;
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdDocIds as $id) {
                $doc = $em->find(Document::class, Uuid::fromString($id));
                if (null !== $doc) {
                    $em->remove($doc);
                }
            }
            $issuer = $em->find(Issuer::class, Uuid::fromString($this->issuerId));
            if (null !== $issuer) {
                $em->remove($issuer);
            }
            foreach (array_filter([$this->userEmail, $this->viewerEmail]) as $email) {
                $user = $em->getRepository(User::class)->findOneBy(['email.value' => $email]);
                if (null !== $user) {
                    $em->remove($user);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        foreach ($this->writtenFiles as $file) {
            $this->storage->delete($file);
        }
        parent::tearDown();
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }

    public function test_list_renders(): void
    {
        $this->client->request('GET', '/cabinet/certificate/document');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Документы', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_form_renders(): void
    {
        $this->client->request('GET', '/cabinet/certificate/document/create');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Добавление документа', (string) $this->client->getResponse()->getContent());
    }

    public function test_create_persists_and_redirects(): void
    {
        $title = 'CTRL-'.bin2hex(random_bytes(3));
        $this->client->request('POST', '/cabinet/certificate/document/create', [
            'kind' => 'conclusion',
            'title' => $title,
            'issuerId' => $this->issuerId,
            'issuedAt' => '2023-01-01',
            'subject' => 'С5, 15-25 лет',
            'references' => [['type' => 'coating_system', 'id' => (string) Uuid::v7()]],
        ]);

        self::assertResponseRedirects('/cabinet/certificate/document');

        $found = $this->repo->findByFilter(new DocumentsFilter(pager: Pager::fromPage(1, 10), query: $title));
        self::assertSame(1, $found->total);
        $this->createdDocIds[] = $found->items[0]->getId();
    }

    public function test_delete_removes_and_redirects(): void
    {
        $id = $this->createViaBus('DEL-'.bin2hex(random_bytes(3)));

        $this->client->request('GET', '/cabinet/certificate/document/'.$id.'/delete');
        self::assertResponseRedirects('/cabinet/certificate/document');

        $this->em->clear();
        self::assertNull($this->repo->findOneById($id));
    }

    public function test_download_streams_pdf(): void
    {
        $id = $this->createViaBus('DL-'.bin2hex(random_bytes(3)), withFile: true);

        $this->em->clear();
        $this->writtenFiles[] = (string) $this->repo->findOneById($id)?->getFile();

        $this->client->request('GET', '/cabinet/certificate/document/'.$id.'/download');

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function test_preview_renders_document_fragment(): void
    {
        $id = $this->createViaBus('PRV-'.bin2hex(random_bytes(3)));

        $this->client->request('GET', '/cabinet/certificate/document/'.$id.'/preview');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('documentPreview-'.$id, (string) $this->client->getResponse()->getContent());
    }

    public function test_download_with_cyrillic_title_succeeds(): void
    {
        // Кириллическое название → Content-Disposition требует ASCII-фолбэк, иначе 500.
        $id = $this->createViaBus('Заключение ЦС-'.bin2hex(random_bytes(3)), withFile: true);

        $this->em->clear();
        $this->writtenFiles[] = (string) $this->repo->findOneById($id)?->getFile();

        $this->client->request('GET', '/cabinet/certificate/document/'.$id.'/download');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function test_non_admin_views_documents_but_cannot_manage(): void
    {
        // Документ заводим как админ (setUp залогинил админа), затем переключаемся на обычного юзера.
        $id = $this->createViaBus('VIEW-'.bin2hex(random_bytes(3)));
        $this->loginNonAdmin();

        // Просмотр (список + превью) — открыт всем авторизованным.
        $this->client->request('GET', '/cabinet/certificate/document');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/cabinet/certificate/document/'.$id.'/preview');
        self::assertResponseIsSuccessful();

        // Создание/управление — только ROLE_ADMIN.
        $createUrl = static::getContainer()->get('router')->generate('app_cabinet_certificate_document_create');
        $this->client->request('GET', $createUrl);
        self::assertResponseStatusCodeSame(403);
    }

    private function loginNonAdmin(): void
    {
        $c = $this->client->getContainer();
        $this->viewerEmail = 'doc_viewer_'.uniqid('', true).'@example.com';
        $viewer = new User(new Email($this->viewerEmail));
        $viewer->setPassword('test_password', $c->get(UserPasswordHasherInterface::class));
        $this->setPrivate($viewer, 'isActive', true);
        // Без ROLE_ADMIN — обычный авторизованный пользователь.
        $this->em->persist($viewer);
        $this->em->flush();
        $this->client->loginUser($viewer);
    }

    private function createViaBus(string $title, bool $withFile = false): string
    {
        $file = null;
        if ($withFile) {
            $path = tempnam(sys_get_temp_dir(), 'scan').'.pdf';
            file_put_contents($path, "%PDF-1.4\ntest\n%%EOF");
            $file = new UploadedFile($path, 'scan.pdf', 'application/pdf', null, true);
        }

        $result = $this->commandBus->execute(new CreateDocumentCommand(
            DocumentKind::Conclusion,
            $title,
            $this->issuerId,
            new \DateTimeImmutable('2023-01-01'),
            null,
            'С5, 15-25 лет',
            null,
            null,
            [new Reference(ReferenceType::CoatingSystem, Uuid::v7())],
            $file,
        ));
        \assert($result instanceof CreateDocumentCommandResult);
        $this->createdDocIds[] = $result->id;

        return $result->id;
    }
}
