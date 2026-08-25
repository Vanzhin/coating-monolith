<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentsActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private DocumentRepositoryInterface $documents;
    private IssuerSpecification $issuerSpec;
    private string $userEmail;
    private string $systemId;
    private string $docId = '';
    private ?Uuid $issuerId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->documents = $c->get(DocumentRepositoryInterface::class);
        $this->issuerSpec = $c->get(IssuerSpecification::class);

        $this->userEmail = 'docs_action_'.uniqid('', true).'@example.com';
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);
        $this->em->persist($user);

        $issuerUuid = Uuid::v7();
        $this->em->persist(new Issuer($issuerUuid, 'DA-Изд-'.bin2hex(random_bytes(3)), $this->issuerSpec));
        $this->issuerId = $issuerUuid;

        $this->systemId = (string) Uuid::v7();
        $docUuid = Uuid::v7();
        $this->documents->add(new Document(
            $docUuid,
            DocumentKind::Conclusion,
            'DA-DOC',
            $issuerUuid,
            new \DateTimeImmutable('2023-01-01'),
            null,
            'С5',
            null,
            null,
            null,
            new Reference(ReferenceType::CoatingSystem, Uuid::fromString($this->systemId)),
        ));
        $this->docId = (string) $docUuid;
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            if ('' !== $this->docId) {
                $doc = $em->find(Document::class, Uuid::fromString($this->docId));
                if (null !== $doc) {
                    $em->remove($doc);
                }
            }
            if (null !== $this->issuerId) {
                $issuer = $em->find(Issuer::class, $this->issuerId);
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

    public function test_returns_system_documents_json(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/'.$this->systemId.'/documents');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        $items = $payload['data']['items'] ?? [];
        self::assertCount(1, $items);
        self::assertSame('DA-DOC', $items[0]['title']);
        self::assertSame('Заключение', $items[0]['kindLabel']);
    }
}
