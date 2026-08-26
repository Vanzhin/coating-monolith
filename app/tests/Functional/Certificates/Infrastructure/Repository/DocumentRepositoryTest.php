<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Infrastructure\Repository;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class DocumentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private DocumentRepositoryInterface $repo;

    /** @var list<Uuid> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(DocumentRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $doc = $em->find(Document::class, $id);
                if (null !== $doc) {
                    $em->remove($doc);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    /**
     * @param list<Reference> $refs
     */
    private function makeDoc(
        array $refs,
        string $title = 'DOC-1',
        string $subject = 'С5, 15-25 лет',
        ?string $description = null,
        DocumentKind $kind = DocumentKind::Conclusion,
        ?Uuid $issuerId = null,
        ?string $file = null,
    ): Document {
        $id = Uuid::v7();
        $doc = new Document(
            $id,
            $kind,
            $title,
            $issuerId ?? Uuid::v7(),
            new \DateTimeImmutable('2023-01-01'),
            null,
            $subject,
            $description,
            'ГОСТ Р 58346',
            $file,
            ...$refs,
        );
        $this->repo->add($doc);
        $this->createdIds[] = $id;

        return $doc;
    }

    public function test_round_trip_preserves_jsonb_refs_and_enum(): void
    {
        $sys = Uuid::v7();
        $coat = Uuid::v7();
        $doc = $this->makeDoc(
            [new Reference(ReferenceType::CoatingSystem, $sys), new Reference(ReferenceType::Coating, $coat)],
            kind: DocumentKind::Certificate,
        );
        $id = $doc->getId();

        $this->em->clear();

        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame(DocumentKind::Certificate, $loaded->getKind());
        self::assertCount(2, $loaded->references());
        self::assertSame(ReferenceType::CoatingSystem, $loaded->references()[0]->referenceType);
        self::assertSame((string) $sys, (string) $loaded->references()[0]->referenceId);
    }

    public function test_find_by_reference_containment(): void
    {
        $sysA = Uuid::v7();
        $sysB = Uuid::v7();
        $doc = $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $sysA)]);

        $this->em->clear();

        $found = $this->repo->findByReference(new Reference(ReferenceType::CoatingSystem, $sysA));
        $ids = array_map(static fn (Document $d) => $d->getId(), $found);
        self::assertContains($doc->getId(), $ids);

        $none = $this->repo->findByReference(new Reference(ReferenceType::CoatingSystem, $sysB));
        $noneIds = array_map(static fn (Document $d) => $d->getId(), $none);
        self::assertNotContains($doc->getId(), $noneIds);
    }

    public function test_count_by_references_groups_per_owner(): void
    {
        $sysA = Uuid::v7();
        $sysB = Uuid::v7();
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $sysA)]);
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $sysA)]);
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $sysB)]);

        $this->em->clear();

        $counts = $this->repo->countByReferences(
            ReferenceType::CoatingSystem,
            new StringCollection((string) $sysA, (string) $sysB),
        );

        self::assertSame(2, $counts[(string) $sysA] ?? 0);
        self::assertSame(1, $counts[(string) $sysB] ?? 0);
    }

    public function test_find_by_filter_full_text(): void
    {
        $token = 'ftsq'.bin2hex(random_bytes(3));
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, Uuid::v7())], subject: 'проверка '.$token.' стойкость');

        $this->em->clear();

        $result = $this->repo->findByFilter(new DocumentsFilter(pager: Pager::fromPage(1, 10), query: $token));
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
    }

    public function test_find_by_filter_by_kind_and_issuer(): void
    {
        $issuer = Uuid::v7();
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, Uuid::v7())], title: 'K-concl', kind: DocumentKind::Conclusion, issuerId: $issuer);
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, Uuid::v7())], title: 'K-cert', kind: DocumentKind::Certificate, issuerId: $issuer);

        $this->em->clear();

        $byKind = $this->repo->findByFilter(new DocumentsFilter(pager: Pager::fromPage(1, 50), kind: DocumentKind::Certificate, issuerId: (string) $issuer));
        self::assertSame(1, $byKind->total);
        self::assertSame('K-cert', $byKind->items[0]->getTitle());

        $byIssuer = $this->repo->findByFilter(new DocumentsFilter(pager: Pager::fromPage(1, 50), issuerId: (string) $issuer));
        self::assertSame(2, $byIssuer->total);
    }

    public function test_find_by_filter_by_coating(): void
    {
        $coatA = Uuid::v7();
        $coatB = Uuid::v7();
        $this->makeDoc([new Reference(ReferenceType::Coating, $coatA)], title: 'has-coatA');
        // Тот же id, но тип СИСТЕМА — фильтр покрытий его матчить не должен.
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $coatA)], title: 'system-not-coating');
        $this->makeDoc([new Reference(ReferenceType::Coating, $coatB)], title: 'has-coatB');

        $this->em->clear();

        $result = $this->repo->findByFilter(new DocumentsFilter(
            pager: Pager::fromPage(1, 50),
            coatingIds: new StringCollection((string) $coatA),
        ));

        $titles = array_map(static fn (Document $d) => $d->getTitle(), $result->items);
        self::assertContains('has-coatA', $titles);
        self::assertNotContains('system-not-coating', $titles);
        self::assertNotContains('has-coatB', $titles);
    }

    public function test_downloadable_by_references_returns_only_docs_with_file(): void
    {
        $sysWithFile = Uuid::v7();
        $sysNoFile = Uuid::v7();
        $withFile = $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $sysWithFile)], title: 'has-file', file: 'scan-a.pdf');
        $this->makeDoc([new Reference(ReferenceType::CoatingSystem, $sysNoFile)], title: 'no-file');

        $this->em->clear();

        $map = $this->repo->downloadableByReferences(
            ReferenceType::CoatingSystem,
            new StringCollection((string) $sysWithFile, (string) $sysNoFile),
        );

        self::assertSame($withFile->getId(), $map[(string) $sysWithFile] ?? null);
        self::assertArrayNotHasKey((string) $sysNoFile, $map);
    }

    public function test_remove(): void
    {
        $doc = $this->makeDoc([new Reference(ReferenceType::CoatingSystem, Uuid::v7())]);
        $id = $doc->getId();

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);

        $this->repo->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repo->findOneById($id));
    }
}
