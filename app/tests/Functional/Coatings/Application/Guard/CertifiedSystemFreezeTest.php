<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\Guard;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Coatings\Application\UseCase\Command\AppendLayer\AppendLayerCommand;
use App\Coatings\Application\UseCase\Command\AppendLayer\AppendLayerCommandHandler;
use App\Coatings\Application\UseCase\Command\RemoveCoatingSystem\RemoveCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\RemoveCoatingSystem\RemoveCoatingSystemCommandHandler;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class CertifiedSystemFreezeTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;
    use AuthenticatesActorTrait;

    private AppendLayerCommandHandler $appendHandler;
    private RemoveCoatingSystemCommandHandler $removeHandler;
    private DocumentRepositoryInterface $documents;
    private IssuerSpecification $issuerSpec;
    private EntityManagerInterface $em;

    /** @var list<Uuid> */
    private array $certIds = [];
    private ?Uuid $issuerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->appendHandler = $container->get(AppendLayerCommandHandler::class);
        $this->removeHandler = $container->get(RemoveCoatingSystemCommandHandler::class);
        $this->documents = $container->get(DocumentRepositoryInterface::class);
        $this->issuerSpec = $container->get(IssuerSpecification::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        try {
            foreach ($this->certIds as $id) {
                $doc = $em->find(Document::class, $id);
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
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'cert cleanup error: '.$e->getMessage()."\n");
        }
        $this->tearDownFixture($em);
        parent::tearDown();
    }

    private function attachCertificate(): void
    {
        $issuerUuid = Uuid::v7();
        $this->em->persist(new Issuer($issuerUuid, 'Guard-Изд-'.bin2hex(random_bytes(3)), $this->issuerSpec));
        $this->issuerId = $issuerUuid;
        $this->em->flush();

        $docId = Uuid::v7();
        $this->documents->add(new Document(
            $docId,
            DocumentKind::Certificate,
            'GUARD-CERT',
            $issuerUuid,
            new \DateTimeImmutable('2023-01-01'),
            null,
            'R120',
            null,
            null,
            null,
            new Reference(ReferenceType::CoatingSystem, $this->systemId),
        ));
        $this->certIds[] = $docId;
    }

    public function test_append_layer_blocked_when_certified(): void
    {
        $this->attachCertificate();
        $this->em->clear();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/сертификат/');

        ($this->appendHandler)(new AppendLayerCommand(
            systemId: (string) $this->systemId,
            coatingId: (string) $this->coatingId,
            dft: 80,
        ));
    }

    public function test_remove_system_blocked_when_certified(): void
    {
        $this->attachCertificate();
        $this->em->clear();

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/сертификат/');

        ($this->removeHandler)(new RemoveCoatingSystemCommand((string) $this->systemId));
    }

    public function test_append_layer_allowed_without_certificate(): void
    {
        $result = ($this->appendHandler)(new AppendLayerCommand(
            systemId: (string) $this->systemId,
            coatingId: (string) $this->coatingId,
            dft: 80,
        ));

        self::assertNotEmpty($result->layerId);
    }
}
