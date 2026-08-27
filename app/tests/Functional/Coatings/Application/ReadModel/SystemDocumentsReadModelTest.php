<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Application\ReadModel;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Coatings\Application\Service\CoatingSystemOperatingTemperatureCalculator;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
use App\Coatings\Infrastructure\Search\CoatingSystemFinder;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SystemDocumentsReadModelTest extends KernelTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private CoatingSystemFinder $finder;
    private QueryBusInterface $queryBus;
    private DocumentRepositoryInterface $documents;
    private IssuerSpecification $issuerSpec;
    private EntityManagerInterface $em;
    private string $systemTitle = '';

    /** @var list<string> */
    private array $certIds = [];
    private ?Uuid $issuerId = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->finder = $container->get(CoatingSystemFinder::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->documents = $container->get(DocumentRepositoryInterface::class);
        $this->issuerSpec = $container->get(IssuerSpecification::class);
        $this->em = $container->get(EntityManagerInterface::class);
        $this->setUpFixture($container, $this->em);

        $system = $this->em->find(CoatingSystem::class, $this->systemId);
        $this->systemTitle = (string) $system?->getTitle();
        // Детерминированно наполняем поисковый кэш системы. В тестовой среде событие onFlush → upsert
        // срабатывает нестабильно (флаки), а Finder делает INNER JOIN coating_system_search — без строки
        // кэша система не находится. Явный upsert повторяет паттерн остальных функциональных тестов.
        if (null !== $system) {
            $searchCache = static::getContainer()->get(CoatingSystemSearchCacheRepository::class);
            $calculator = static::getContainer()->get(CoatingSystemOperatingTemperatureCalculator::class);
            $searchCache->upsert($system, $calculator->calculate($system));
        }
        $this->attachCertificate();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        try {
            foreach ($this->certIds as $id) {
                $doc = $em->find(Document::class, Uuid::fromString($id));
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
        $this->em->persist(new Issuer($issuerUuid, 'RM-Изд-'.bin2hex(random_bytes(3)), $this->issuerSpec));
        $this->issuerId = $issuerUuid;
        $this->em->flush();

        $docId = Uuid::v7();
        $this->documents->add(new Document(
            $docId,
            DocumentKind::Conclusion,
            'RM-DOC',
            $issuerUuid,
            new \DateTimeImmutable('2023-01-01'),
            null,
            'С5',
            null,
            null,
            null,
            new Reference(ReferenceType::CoatingSystem, $this->systemId),
        ));
        $this->certIds[] = (string) $docId;
    }

    public function test_facet_has_documents_includes_certified_system(): void
    {
        $filter = new CoatingSystemsFilter(search: SearchQuery::tryFromString($this->systemTitle), hasDocuments: true);
        $ids = $this->finder->find($filter)->ids->getList();

        self::assertContains((string) $this->systemId, $ids);
    }

    public function test_facet_without_documents_excludes_certified_system(): void
    {
        $filter = new CoatingSystemsFilter(search: SearchQuery::tryFromString($this->systemTitle), hasDocuments: false);
        $ids = $this->finder->find($filter)->ids->getList();

        self::assertNotContains((string) $this->systemId, $ids);
    }

    public function test_search_query_sets_document_count(): void
    {
        $result = $this->queryBus->execute(new SearchCoatingSystemsQuery(
            new CoatingSystemsFilter(search: SearchQuery::tryFromString($this->systemTitle)),
        ));
        \assert($result instanceof SearchCoatingSystemsQueryResult);

        $mine = null;
        foreach ($result->items as $item) {
            if ($item->id === (string) $this->systemId) {
                $mine = $item;
                break;
            }
        }

        self::assertNotNull($mine);
        self::assertSame(1, $mine->documentCount);
    }
}
