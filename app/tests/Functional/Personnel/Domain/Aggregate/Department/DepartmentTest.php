<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Domain\Aggregate\Department;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Service\DepartmentTreePolicy;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DepartmentTest extends KernelTestCase
{
    private DepartmentTreePolicy $policy;
    private DepartmentRepositoryInterface $repository;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->policy = $container->get(DepartmentTreePolicy::class);
        $this->repository = $container->get(DepartmentRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach (array_reverse($this->createdIds) as $id) {
                $d = $this->repository->findOneById($id);
                if (null !== $d) {
                    $this->repository->remove($d);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function create(string $title): Department
    {
        $department = new Department(UuidService::generate(), $title, UuidService::generate(), null, null, $this->policy);
        $this->repository->add($department);
        $this->createdIds[] = $department->getId();

        return $department;
    }

    public function test_create_persist_and_find_by_id(): void
    {
        $title = 'Отдел охраны труда '.uniqid('', true);
        $created = $this->create($title);

        $byId = $this->repository->findOneById($created->getId());
        self::assertNotNull($byId);
        self::assertSame($created->getId(), $byId->getId());
        self::assertSame($title, $byId->getTitle());
        self::assertSame($created->getCompanyId(), $byId->getCompanyId());
        self::assertNull($byId->getParentId());
        self::assertNull($byId->getHeadUserUlid());
    }

    public function test_empty_title_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Department(UuidService::generate(), '   ', UuidService::generate(), null, null, $this->policy);
    }

    public function test_too_long_title_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Department(UuidService::generate(), str_repeat('я', 151), UuidService::generate(), null, null, $this->policy);
    }

    public function test_rename_reassigns_title(): void
    {
        $created = $this->create('Старое название '.uniqid('', true));
        $newTitle = 'Новое название '.uniqid('', true);

        $created->rename($newTitle);
        $this->repository->add($created);

        $reloaded = $this->repository->findOneById($created->getId());
        self::assertNotNull($reloaded);
        self::assertSame($newTitle, $reloaded->getTitle());
    }

    public function test_rename_to_empty_title_is_rejected(): void
    {
        $created = $this->create('Отдел '.uniqid('', true));

        $this->expectException(AppException::class);
        $created->rename('   ');
    }

    public function test_assign_head_trims_and_stores_ulid(): void
    {
        $created = $this->create('Отдел '.uniqid('', true));
        $ulid = UuidService::generateUlid();

        $created->assignHead('  '.$ulid.'  ');

        self::assertSame($ulid, $created->getHeadUserUlid());
    }
}
