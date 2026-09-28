<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Domain\Aggregate\Position;

use App\Personnel\Domain\Aggregate\Position\Position;
use App\Personnel\Domain\Aggregate\Position\Specification\PositionSpecification;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PositionTest extends KernelTestCase
{
    private PositionSpecification $specification;
    private PositionRepositoryInterface $repository;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->specification = $container->get(PositionSpecification::class);
        $this->repository = $container->get(PositionRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $p = $this->repository->findOneById($id);
                if (null !== $p) {
                    $this->repository->remove($p);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function create(string $title): Position
    {
        $position = new Position(UuidService::generate(), $title, $this->specification);
        $this->repository->add($position);
        $this->createdIds[] = $position->getId();

        return $position;
    }

    public function test_create_persist_and_find_by_id_and_title(): void
    {
        $title = 'Маляр '.uniqid('', true);
        $created = $this->create($title);

        $byId = $this->repository->findOneById($created->getId());
        self::assertNotNull($byId);
        self::assertSame($created->getId(), $byId->getId());
        self::assertSame($title, $byId->getTitle());

        $byTitle = $this->repository->findOneByTitle($title);
        self::assertNotNull($byTitle);
        self::assertSame($created->getId(), $byTitle->getId());
    }

    public function test_duplicate_title_is_rejected(): void
    {
        $title = 'Дубль '.uniqid('', true);
        $this->create($title);

        $this->expectException(AppException::class);
        new Position(UuidService::generate(), $title, $this->specification);
    }

    public function test_empty_title_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Position(UuidService::generate(), '   ', $this->specification);
    }

    public function test_too_long_title_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Position(UuidService::generate(), str_repeat('я', 151), $this->specification);
    }

    public function test_rename_reassigns_title_and_checks_uniqueness(): void
    {
        $created = $this->create('Инженер ОТ '.uniqid('', true));
        $newTitle = 'Инспектор ОТ '.uniqid('', true);

        $created->rename($newTitle);
        $this->repository->add($created);

        $reloaded = $this->repository->findOneById($created->getId());
        self::assertNotNull($reloaded);
        self::assertSame($newTitle, $reloaded->getTitle());
    }

    public function test_suggest_finds_by_prefix(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $title = 'Суггест-'.$suffix;
        $created = $this->create($title);

        $result = $this->repository->suggest('Суггест-'.$suffix, 10);

        $ids = array_map(static fn (Position $p) => $p->getId(), $result);
        self::assertContains($created->getId(), $ids);
    }

    public function test_suggest_returns_empty_for_blank_query(): void
    {
        self::assertSame([], $this->repository->suggest('   ', 10));
    }

    public function test_find_by_ids_returns_requested_positions(): void
    {
        $a = $this->create('Прораб '.uniqid('', true));
        $b = $this->create('Бригадир '.uniqid('', true));
        $this->create('Не в выборке '.uniqid('', true));

        $result = $this->repository->findByIds(new StringCollection($a->getId(), $b->getId()));

        $ids = array_map(static fn (Position $p) => $p->getId(), $result);
        self::assertCount(2, $result);
        self::assertContains($a->getId(), $ids);
        self::assertContains($b->getId(), $ids);
    }

    public function test_find_by_ids_returns_empty_array_for_empty_collection(): void
    {
        self::assertSame([], $this->repository->findByIds(new StringCollection()));
    }
}
