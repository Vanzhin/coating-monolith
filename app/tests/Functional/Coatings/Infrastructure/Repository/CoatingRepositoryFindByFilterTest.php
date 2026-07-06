<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

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
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Поведение findByFilter (Option C):
 *  - fullText AND-search — основной путь;
 *  - fuzzy-fallback ТОЛЬКО для single-word запросов (опечатки);
 *  - на multi-word без точного матча возвращается пустой результат
 *    (а не «похожее но не то» через fuzzy на всю строку).
 */
final class CoatingRepositoryFindByFilterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingRepositoryInterface $repo;
    private string $coatingId;
    private string $manufacturerId;
    private string $tagId;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(CoatingRepositoryInterface::class);

        // Короткий cyrillic-only suffix — чтобы поисковые запросы помещались
        // в 50-символьный лимит CoatingsFilter и не дробились FTS-лексером
        // на не-кириллических символах.
        $suffix = bin2hex(random_bytes(2));

        $manufacturer = new Manufacturer(
            'Производитель' . $suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);

        // Tag — уникальная лексема, прилетит в search_vector через триггер.
        $tag = new CoatingTag(
            'тагунико' . $suffix,
            $container->get(CoatingTagSpecification::class),
            CoatingTag::TYPE_GENERAL,
        );
        $container->get(CoatingTagRepositoryInterface::class)->add($tag);

        $coating = new Coating(
            UuidService::generateUuid(),
            'Образец' . $suffix,
            'Нейтральное описание без специфичных слов.',
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
        $this->repo->add($coating);
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
            if ($coating !== null) {
                $em->remove($coating);
            }
            $tag = static::getContainer()->get(CoatingTagRepositoryInterface::class)->findOneById($this->tagId);
            if ($tag !== null) {
                $em->remove($tag);
            }
            $manufacturer = $em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
            if ($manufacturer !== null) {
                $em->remove($manufacturer);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testSingleWordFindsCoatingViaTag(): void
    {
        // Single-word точно матчит лексему тега → fullText AND проходит.
        // Префикс тоже работает — берём 6 символов от уникального title тега.
        $prefix = mb_substr($this->getTagTitle(), 0, 6);
        $filter = new CoatingsFilter(SearchQuery::tryFromString($prefix), pager: Pager::fromPage(1, 50));

        $result = $this->repo->findByFilter($filter);

        $ids = array_map(fn(Coating $c) => $c->getId(), $result->items);
        self::assertContains($this->coatingId, $ids);
    }

    public function testMultiWordWithUnmatchedTokenReturnsEmpty(): void
    {
        // Multi-word: первое слово — наш тег (есть), второе — заведомо
        // отсутствующая лексема. AND fullText даёт 0. fuzzy НЕ запускается
        // (multi-word) → результат пуст.
        $query = $this->getTagTitle() . ' нетлексикс';
        $filter = new CoatingsFilter(SearchQuery::tryFromString($query), pager: Pager::fromPage(1, 50));

        $result = $this->repo->findByFilter($filter);

        self::assertSame(0, $result->total, 'Multi-word без полного AND-матча должен давать пусто, а не fuzzy-похожее.');
    }

    public function testSingleWordTypoTriggersFuzzyFallback(): void
    {
        // Single-word опечатка (последняя буква изменена) — fullText ничего
        // не находит, но fuzzy WORD_SIMILARITY должен сматчить через title.
        $coating = $this->em->find(Coating::class, Uuid::fromString($this->coatingId));
        $title = $coating->getTitle();
        $typo = mb_substr($title, 0, -1) . 'я'; // меняем последний символ на cyrillic

        $filter = new CoatingsFilter(SearchQuery::tryFromString($typo), pager: Pager::fromPage(1, 50));

        $result = $this->repo->findByFilter($filter);

        $ids = array_map(fn(Coating $c) => $c->getId(), $result->items);
        self::assertContains($this->coatingId, $ids, 'Single-word опечатка должна ловиться fuzzy fallback\'ом.');
    }

    private function getTagTitle(): string
    {
        return static::getContainer()
            ->get(CoatingTagRepositoryInterface::class)
            ->findOneById($this->tagId)
            ->getTitle();
    }
}
