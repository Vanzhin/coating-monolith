<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Search;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Coatings\Infrastructure\Search\CoatingTagFinder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CoatingTagFinderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoatingTagFinder $finder;
    private CoatingTagRepositoryInterface $repo;
    private CoatingTagSpecification $spec;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->finder = $container->get(CoatingTagFinder::class);
        $this->repo = $container->get(CoatingTagRepositoryInterface::class);
        $this->spec = $container->get(CoatingTagSpecification::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdIds as $id) {
            $tag = $this->repo->findOneById($id);
            if (null !== $tag) {
                $this->em->remove($tag);
            }
        }
        $this->em->flush();
        $this->em->clear();
        parent::tearDown();
    }

    public function test_suggest_returns_general_tags_by_prefix(): void
    {
        $forConcrete = $this->makeTag('Для бетона', CoatingTag::TYPE_GENERAL);
        $forSteel = $this->makeTag('Для стали', CoatingTag::TYPE_GENERAL);
        $topTag = $this->makeTag('top_test_unique', 'CoatingCoatType');

        $result = $this->finder->suggest('для', CoatingTag::TYPE_GENERAL);

        $titles = array_map(fn (CoatingTag $t) => $t->getTitle(), $result);
        self::assertContains('Для бетона', $titles);
        self::assertContains('Для стали', $titles);
        self::assertNotContains('top_test_unique', $titles, 'Не general — не должен попасть');
    }

    public function test_suggest_falls_back_to_fuzzy_when_fts_empty(): void
    {
        $this->makeTag('Для бетона', CoatingTag::TYPE_GENERAL);

        // 'бетано' — опечатка, FTS prefix не сматчится; fuzzy должен поймать.
        $result = $this->finder->suggest('бетано', CoatingTag::TYPE_GENERAL);

        $titles = array_map(fn (CoatingTag $t) => $t->getTitle(), $result);
        self::assertContains('Для бетона', $titles);
    }

    public function test_suggest_empty_query_returns_empty(): void
    {
        $this->makeTag('Для бетона', CoatingTag::TYPE_GENERAL);

        self::assertSame([], $this->finder->suggest('', CoatingTag::TYPE_GENERAL));
    }

    public function test_suggest_finds_super_by_prefix_supe(): void
    {
        // Чистим leftover из интерактивной отладки в shared dev/test БД,
        // вместе с pivot-связями (иначе FK violation).
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            "DELETE FROM coatings_coating_coating_tag WHERE tag_id IN (SELECT id FROM coatings_coating_tag WHERE title = 'супер')"
        );
        $conn->executeStatement(
            "DELETE FROM coatings_coating_tag WHERE title = 'супер'"
        );

        $this->makeTag('супер', CoatingTag::TYPE_GENERAL);

        // Острый кейс: «супе» русским стеммером сводится к лексеме «суп»
        // (-е — типовое падежное окончание). Indexed «супер» → лексема «супер».
        // Префикс-tsquery 'суп:*' должен матчить лексему «супер»; если стеммер
        // ведёт себя иначе — поймает fuzzy-fallback (WORD_SIMILARITY).
        $result = $this->finder->suggest('супе', CoatingTag::TYPE_GENERAL);

        $titles = array_map(fn (CoatingTag $t) => $t->getTitle(), $result);
        self::assertContains('супер', $titles);
    }

    public function test_suggest_respects_limit(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->makeTag('Для теста '.$i, CoatingTag::TYPE_GENERAL);
        }

        $result = $this->finder->suggest('для теста', CoatingTag::TYPE_GENERAL, limit: 2);

        self::assertCount(2, $result);
    }

    private function makeTag(string $title, ?string $type): CoatingTag
    {
        $tag = new CoatingTag($title, $this->spec, $type);
        $this->repo->add($tag);
        $this->createdIds[] = $tag->getId();

        return $tag;
    }
}
