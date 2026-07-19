<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Infrastructure\Search\CoatingFinder;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * e2e test: покрытие находится через FTS по тексту general-тега,
 * даже если title/description нейтральны.
 *
 * Требует, чтобы миграция Version20260627161208 (триггеры на pivot) была применена.
 */
final class CoatingFinderFtsTagTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingFinder $finder;
    private string $coatingId;
    private string $manufacturerId;
    private string $tagId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->finder = $container->get(CoatingFinder::class);

        $suffix = uniqid('', true);

        // Manufacturer
        $manufacturer = new Manufacturer('TestMfg_'.$suffix, $container->get(ManufacturerSpecification::class));
        $this->em->persist($manufacturer);

        // General tag — содержит уникальное слово «бетонxxx» которого нет в title/description.
        $tag = new CoatingTag('Для бетона FTS_'.$suffix, $container->get(CoatingTagSpecification::class), CoatingTag::TYPE_GENERAL);
        $container->get(CoatingTagRepositoryInterface::class)->add($tag);

        // Coating с нейтральным title/description — должно матчиться только за счёт тега.
        $coating = new Coating(
            UuidService::generateUuid(),
            'NeutralTitle_'.$suffix,
            'Описание не содержит нужного слова.',
            50,
            1.5,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            5,
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            new DryingTimeSeries(new TimeAtTemperature(20, 1440)),
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            null,
            1.0,
            null,
            $manufacturer,
            $container->get(CoatingSpecification::class),
        );
        $coating->replaceTags([$tag]);
        $container->get(CoatingRepositoryInterface::class)->add($coating);
        $this->em->flush();

        $this->coatingId = $coating->getId();
        $this->manufacturerId = $manufacturer->getId();
        $this->tagId = $tag->getId();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $coating = $em->find(Coating::class, Uuid::fromString($this->coatingId));
            if (null !== $coating) {
                $em->remove($coating);
            }
            $tag = static::getContainer()->get(CoatingTagRepositoryInterface::class)->findOneById($this->tagId);
            if (null !== $tag) {
                $em->remove($tag);
            }
            $manufacturer = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if (null !== $manufacturer) {
                $em->remove($manufacturer);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_fts_finds_coating_by_tag_title(): void
    {
        // Поиск по слову «бетона» — присутствует только в теге, не в title/description → должен найти.
        $filter = new CoatingsFilter(SearchQuery::tryFromString('бетона'), pager: Pager::fromPage(1, 50));
        $result = $this->finder->fullText($filter);

        $ids = array_map(fn (Coating $c) => $c->getId(), $result->items);
        self::assertContains(
            $this->coatingId,
            $ids,
            'Покрытие должно находиться по тексту тега, даже если title/description нейтральны.',
        );
    }
}
