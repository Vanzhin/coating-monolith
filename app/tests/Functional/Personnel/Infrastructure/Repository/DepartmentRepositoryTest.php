<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Infrastructure\Repository;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Service\DepartmentTreePolicy;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DepartmentRepositoryTest extends KernelTestCase
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

    private function create(string $title, string $companyId, ?string $parentId = null, ?string $headUserUlid = null): Department
    {
        $department = new Department(UuidService::generate(), $title, $companyId, $parentId, $headUserUlid, $this->policy);
        $this->repository->add($department);
        $this->createdIds[] = $department->getId();

        return $department;
    }

    public function test_find_ancestors_returns_chain_bottom_up_excluding_node_itself(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $mid = $this->create('Цех '.uniqid('', true), $companyId, $root->getId());
        $leaf = $this->create('Участок '.uniqid('', true), $companyId, $mid->getId());

        $ancestors = $this->repository->findAncestors($leaf->getId());

        self::assertCount(2, $ancestors);
        self::assertSame($mid->getId(), $ancestors[0]->getId());
        self::assertSame($root->getId(), $ancestors[1]->getId());
    }

    public function test_find_ancestors_of_root_is_empty(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);

        self::assertSame([], $this->repository->findAncestors($root->getId()));
    }

    public function test_find_ancestors_of_unknown_id_is_empty(): void
    {
        self::assertSame([], $this->repository->findAncestors(UuidService::generate()));
    }

    public function test_find_children_returns_direct_children_only(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $childA = $this->create('Цех А '.uniqid('', true), $companyId, $root->getId());
        $childB = $this->create('Цех Б '.uniqid('', true), $companyId, $root->getId());
        $grandchild = $this->create('Участок '.uniqid('', true), $companyId, $childA->getId());

        $children = $this->repository->findChildren($root->getId());
        $ids = array_map(static fn (Department $d) => $d->getId(), $children);

        self::assertCount(2, $children);
        self::assertContains($childA->getId(), $ids);
        self::assertContains($childB->getId(), $ids);
        self::assertNotContains($grandchild->getId(), $ids);
    }

    public function test_find_by_company_returns_all_departments_of_company(): void
    {
        $companyId = UuidService::generate();
        $otherCompanyId = UuidService::generate();
        $a = $this->create('Отдел А '.uniqid('', true), $companyId);
        $b = $this->create('Отдел Б '.uniqid('', true), $companyId, $a->getId());
        $this->create('Чужой отдел '.uniqid('', true), $otherCompanyId);

        $result = $this->repository->findByCompany($companyId);
        $ids = array_map(static fn (Department $d) => $d->getId(), $result);

        self::assertCount(2, $result);
        self::assertContains($a->getId(), $ids);
        self::assertContains($b->getId(), $ids);
    }

    public function test_find_by_ids_returns_requested_departments(): void
    {
        $companyId = UuidService::generate();
        $a = $this->create('Отдел А '.uniqid('', true), $companyId);
        $b = $this->create('Отдел Б '.uniqid('', true), $companyId);
        $this->create('Не в выборке '.uniqid('', true), $companyId);

        $result = $this->repository->findByIds(new StringCollection($a->getId(), $b->getId()));
        $ids = array_map(static fn (Department $d) => $d->getId(), $result);

        self::assertCount(2, $result);
        self::assertContains($a->getId(), $ids);
        self::assertContains($b->getId(), $ids);
    }

    public function test_find_by_ids_returns_empty_array_for_empty_collection(): void
    {
        self::assertSame([], $this->repository->findByIds(new StringCollection()));
    }

    public function test_assign_and_clear_head(): void
    {
        $companyId = UuidService::generate();
        $headUlid = UuidService::generateUlid();
        $department = $this->create('Отдел '.uniqid('', true), $companyId, null, $headUlid);

        self::assertSame($headUlid, $department->getHeadUserUlid());

        $department->assignHead('  ');
        $this->repository->add($department);

        $reloaded = $this->repository->findOneById($department->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getHeadUserUlid());
    }
}
