<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Adapter;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Coatings\Domain\Service\SystemCertificatesGateway;
use App\Coatings\Infrastructure\Adapter\CertificatesGateway;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class CertificatesGatewayTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentRepositoryInterface $documents;
    private IssuerSpecification $issuerSpec;
    private SystemCertificatesGateway $gateway;
    private string $issuerTitle = '';
    private string $issuerId = '';

    /** @var list<string> */
    private array $createdDocIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->documents = $c->get(DocumentRepositoryInterface::class);
        $this->issuerSpec = $c->get(IssuerSpecification::class);
        // Сервис-порт ещё никем не потребляется (guard — Шаг 2) → в контейнере может быть выпилен.
        // Тестируем конкретный адаптер, собрав его из доступных зависимостей.
        $this->gateway = new CertificatesGateway(
            $this->documents,
            $c->get(IssuerRepositoryInterface::class),
            $c->get(UrlGeneratorInterface::class),
        );

        $this->issuerTitle = 'GW-Изд-'.bin2hex(random_bytes(3));
        $uuid = Uuid::v7();
        $this->em->persist(new Issuer($uuid, $this->issuerTitle, $this->issuerSpec));
        $this->issuerId = (string) $uuid;
        $this->em->flush();
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
            if ('' !== $this->issuerId) {
                $issuer = $em->find(Issuer::class, Uuid::fromString($this->issuerId));
                if (null !== $issuer) {
                    $em->remove($issuer);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function addDoc(string $systemId, string $title = 'GW-DOC'): void
    {
        $id = Uuid::v7();
        $doc = new Document(
            $id,
            DocumentKind::Conclusion,
            $title,
            Uuid::fromString($this->issuerId),
            new \DateTimeImmutable('2023-01-01'),
            null,
            'С5, 15-25 лет',
            null,
            null,
            null,
            new Reference(ReferenceType::CoatingSystem, Uuid::fromString($systemId)),
        );
        $this->documents->add($doc);
        $this->createdDocIds[] = (string) $id;
    }

    public function test_has_certificates(): void
    {
        $sys = (string) Uuid::v7();
        $this->addDoc($sys);
        $this->em->clear();

        self::assertTrue($this->gateway->hasCertificates($sys));
        self::assertFalse($this->gateway->hasCertificates((string) Uuid::v7()));
    }

    public function test_count_by_system_ids(): void
    {
        $sysA = (string) Uuid::v7();
        $sysB = (string) Uuid::v7();
        $this->addDoc($sysA);
        $this->addDoc($sysA);
        $this->addDoc($sysB);
        $this->em->clear();

        $counts = $this->gateway->countBySystemIds(new StringCollection($sysA, $sysB));
        self::assertSame(2, $counts[$sysA] ?? 0);
        self::assertSame(1, $counts[$sysB] ?? 0);
    }

    public function test_list_by_system_maps_read_vo(): void
    {
        $sys = (string) Uuid::v7();
        $this->addDoc($sys, 'GW-LIST-'.bin2hex(random_bytes(3)));
        $this->em->clear();

        $list = $this->gateway->listBySystem($sys);
        self::assertCount(1, $list);
        self::assertSame($this->issuerTitle, $list[0]->issuerTitle);
        self::assertSame('Заключение', $list[0]->kindLabel);
        self::assertFalse($list[0]->hasFile);
        self::assertNull($list[0]->downloadUrl);
    }
}
