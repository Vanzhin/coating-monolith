<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
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
    /** @var list<Uuid> */
    private array $extraCoatingIds = [];

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
            'Производитель'.$suffix,
            $container->get(ManufacturerSpecification::class),
        );
        $this->em->persist($manufacturer);

        // Tag — уникальная лексема, прилетит в search_vector через триггер.
        $tag = new Tag(
            'тагунико'.$suffix,
            $container->get(TagSpecification::class),
            Tag::TYPE_GENERAL,
        );
        $container->get(TagRepositoryInterface::class)->add($tag);

        $coating = new Coating(
            UuidService::generateUuid(),
            'Образец'.$suffix,
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
            foreach ($this->extraCoatingIds as $id) {
                $extra = $em->find(Coating::class, $id);
                if (null !== $extra) {
                    $em->remove($extra);
                }
            }
            $coating = $em->find(Coating::class, Uuid::fromString($this->coatingId));
            if (null !== $coating) {
                $em->remove($coating);
            }
            $tag = static::getContainer()->get(TagRepositoryInterface::class)->findOneById($this->tagId);
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

    public function test_single_word_finds_coating_via_tag(): void
    {
        // Single-word точно матчит лексему тега → fullText AND проходит.
        // Префикс тоже работает — берём 6 символов от уникального title тега.
        $prefix = mb_substr($this->getTagTitle(), 0, 6);
        $filter = new CoatingsFilter(SearchQuery::tryFromString($prefix), pager: Pager::fromPage(1, 50));

        $result = $this->repo->findByFilter($filter);

        $ids = array_map(fn (Coating $c) => $c->getId(), $result->items);
        self::assertContains($this->coatingId, $ids);
    }

    public function test_multi_word_with_unmatched_token_returns_empty(): void
    {
        // Multi-word: первое слово — наш тег (есть), второе — заведомо
        // отсутствующая лексема. AND fullText даёт 0. fuzzy НЕ запускается
        // (multi-word) → результат пуст.
        $query = $this->getTagTitle().' нетлексикс';
        $filter = new CoatingsFilter(SearchQuery::tryFromString($query), pager: Pager::fromPage(1, 50));

        $result = $this->repo->findByFilter($filter);

        self::assertSame(0, $result->total, 'Multi-word без полного AND-матча должен давать пусто, а не fuzzy-похожее.');
    }

    public function test_single_word_typo_triggers_fuzzy_fallback(): void
    {
        // Single-word опечатка (последняя буква изменена) — fullText ничего
        // не находит, но fuzzy WORD_SIMILARITY должен сматчить через title.
        $coating = $this->em->find(Coating::class, Uuid::fromString($this->coatingId));
        $title = $coating->getTitle();
        $typo = mb_substr($title, 0, -1).'я'; // меняем последний символ на cyrillic

        $filter = new CoatingsFilter(SearchQuery::tryFromString($typo), pager: Pager::fromPage(1, 50));

        $result = $this->repo->findByFilter($filter);

        $ids = array_map(fn (Coating $c) => $c->getId(), $result->items);
        self::assertContains($this->coatingId, $ids, 'Single-word опечатка должна ловиться fuzzy fallback\'ом.');
    }

    public function test_fuzzy_results_are_paginated_within_ten_result_limit(): void
    {
        $manufacturer = $this->em->find(Manufacturer::class, Uuid::fromString($this->manufacturerId));
        \assert($manufacturer instanceof Manufacturer);
        for ($i = 0; $i < 12; ++$i) {
            $coating = new Coating(
                UuidService::generateUuid(),
                sprintf('Антигрязепокрытие %02d', $i),
                'Описание материала.',
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
                self::getContainer()->get(CoatingSpecification::class),
            );
            $this->repo->add($coating);
            $this->extraCoatingIds[] = $coating->id;
        }

        $search = new SearchQuery('антигрязепокрытме');
        $allIds = [];
        foreach ([1, 2, 3, 4] as $page) {
            $result = $this->repo->findByFilter(new CoatingsFilter(
                search: $search,
                manufacturerIds: new StringCollection($this->manufacturerId),
                pager: Pager::fromPage($page, 3),
            ));
            self::assertSame(10, $result->total);
            self::assertCount(4 === $page ? 1 : 3, $result->items);
            array_push($allIds, ...array_map(static fn (Coating $c): string => $c->getId(), $result->items));
        }
        self::assertCount(10, array_unique($allIds));

        $pastLimit = $this->repo->findByFilter(new CoatingsFilter(
            search: $search,
            manufacturerIds: new StringCollection($this->manufacturerId),
            pager: Pager::fromPage(5, 3),
        ));
        self::assertSame(10, $pastLimit->total);
        self::assertSame([], $pastLimit->items);

        $selected = $this->repo->findByFilter(new CoatingsFilter(
            search: $search,
            pager: Pager::fromPage(1, 3),
            coatingIds: new StringCollection($allIds[0], $allIds[1]),
        ));
        self::assertSame(2, $selected->total);
        self::assertCount(2, $selected->items);
    }

    private function getTagTitle(): string
    {
        return static::getContainer()
            ->get(TagRepositoryInterface::class)
            ->findOneById($this->tagId)
            ->getTitle();
    }
}
