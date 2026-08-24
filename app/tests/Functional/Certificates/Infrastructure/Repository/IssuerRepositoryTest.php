<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Infrastructure\Repository;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class IssuerRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IssuerRepositoryInterface $repo;
    private IssuerSpecification $spec;

    /** @var list<Uuid> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->repo = $container->get(IssuerRepositoryInterface::class);
        $this->spec = $container->get(IssuerSpecification::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $issuer = $em->find(Issuer::class, $id);
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

    private function make(string $title): Issuer
    {
        $id = Uuid::v7();
        $issuer = new Issuer($id, $title, $this->spec);
        $this->repo->add($issuer);
        $this->createdIds[] = $id;

        return $issuer;
    }

    public function test_add_and_find_by_id_round_trip(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $id = Uuid::v7();
        $issuer = new Issuer($id, 'НПЦ Самара-'.$suffix, $this->spec);
        $this->repo->add($issuer);
        $this->createdIds[] = $id;

        $this->em->clear();

        $loaded = $this->repo->findOneById((string) $id);
        self::assertNotNull($loaded);
        self::assertSame((string) $id, $loaded->getId());
        self::assertSame('НПЦ Самара-'.$suffix, $loaded->getTitle());
    }

    public function test_find_one_by_title(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $made = $this->make('ГосНИИГА-'.$suffix);

        $this->em->clear();

        $loaded = $this->repo->findOneByTitle('ГосНИИГА-'.$suffix);
        self::assertNotNull($loaded);
        self::assertSame($made->getId(), $loaded->getId());
    }

    public function test_find_by_ids(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $a = $this->make('ЦНИИТС-'.$suffix);
        $b = $this->make('ЛКП-'.$suffix);

        $this->em->clear();

        $found = $this->repo->findByIds(new StringCollection($a->getId(), $b->getId()));
        $ids = array_map(fn (Issuer $i) => $i->getId(), $found);
        self::assertContains($a->getId(), $ids);
        self::assertContains($b->getId(), $ids);
    }

    public function test_find_by_ids_empty_returns_empty(): void
    {
        self::assertSame([], $this->repo->findByIds(new StringCollection()));
    }

    public function test_suggest_is_case_insensitive_prefix(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $target = $this->make('ГосНИИГА-'.$suffix);
        $this->make('Другая лаба-'.$suffix);

        $this->em->clear();

        $found = $this->repo->suggest('госниига-'.$suffix, 10);
        $ids = array_map(fn (Issuer $i) => $i->getId(), $found);
        self::assertContains($target->getId(), $ids);
        self::assertCount(1, $found);
    }

    public function test_suggest_empty_query_returns_empty(): void
    {
        self::assertSame([], $this->repo->suggest('   ', 10));
    }

    public function test_find_by_filter_title_contains_with_pagination(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->make('Лаборатория Альфа-'.$suffix);
        $this->make('Лаборатория Бета-'.$suffix);

        $this->em->clear();

        $result = $this->repo->findByFilter(new IssuersFilter(pager: Pager::fromPage(1, 10), title: 'Лаборатория Альфа-'.$suffix));
        self::assertSame(1, $result->total);
        self::assertCount(1, $result->items);
    }

    public function test_remove(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $made = $this->make('Для удаления-'.$suffix);
        $id = $made->getId();

        $this->em->clear();
        $loaded = $this->repo->findOneById($id);
        self::assertNotNull($loaded);

        $this->repo->remove($loaded);
        $this->em->clear();

        self::assertNull($this->repo->findOneById($id));
    }
}
