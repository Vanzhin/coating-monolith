<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Domain\Service;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Service\DepartmentTreePolicy;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DepartmentTreePolicyTest extends KernelTestCase
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

    private function create(string $title, string $companyId, ?string $parentId = null): Department
    {
        $department = new Department(UuidService::generate(), $title, $companyId, $parentId, null, $this->policy);
        $this->repository->add($department);
        $this->createdIds[] = $department->getId();

        return $department;
    }

    public function test_valid_parent_same_company_is_accepted(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $child = $this->create('Цех '.uniqid('', true), $companyId, $root->getId());

        self::assertSame($root->getId(), $child->getParentId());
    }

    public function test_parent_from_other_company_is_rejected(): void
    {
        $companyA = UuidService::generate();
        $companyB = UuidService::generate();
        $foreignParent = $this->create('Чужая дирекция '.uniqid('', true), $companyB);

        $this->expectException(AppException::class);
        $this->create('Цех '.uniqid('', true), $companyA, $foreignParent->getId());
    }

    public function test_missing_parent_is_rejected(): void
    {
        $companyId = UuidService::generate();

        $this->expectException(AppException::class);
        $this->create('Цех '.uniqid('', true), $companyId, UuidService::generate());
    }

    public function test_self_parent_is_rejected_on_move(): void
    {
        $companyId = UuidService::generate();
        $node = $this->create('Отдел '.uniqid('', true), $companyId);

        $this->expectException(AppException::class);
        $node->moveTo($node->getId(), $this->policy);
    }

    public function test_cycle_via_descendant_is_rejected_on_move(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $child = $this->create('Цех '.uniqid('', true), $companyId, $root->getId());

        // Пытаемся сделать корень подчинённым собственному потомку — цикл.
        $this->expectException(AppException::class);
        $root->moveTo($child->getId(), $this->policy);
    }

    public function test_cycle_via_multi_hop_descendant_is_rejected_on_move(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $mid = $this->create('Цех '.uniqid('', true), $companyId, $root->getId());
        $leaf = $this->create('Участок '.uniqid('', true), $companyId, $mid->getId());

        // Цикл в два хопа: root подчиняем внуку через mid — тот же цикл, что и одноуровневый,
        // но требует подъёма минимум на два уровня выше, чтобы встретить root.
        $this->expectException(AppException::class);
        $root->moveTo($leaf->getId(), $this->policy);
    }

    public function test_move_to_valid_new_parent_updates_parent_id(): void
    {
        $companyId = UuidService::generate();
        $branchA = $this->create('Филиал А '.uniqid('', true), $companyId);
        $branchB = $this->create('Филиал Б '.uniqid('', true), $companyId);
        $node = $this->create('Отдел '.uniqid('', true), $companyId, $branchA->getId());

        $node->moveTo($branchB->getId(), $this->policy);
        $this->repository->add($node);

        $reloaded = $this->repository->findOneById($node->getId());
        self::assertNotNull($reloaded);
        self::assertSame($branchB->getId(), $reloaded->getParentId());
    }

    public function test_move_to_root_clears_parent(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $node = $this->create('Отдел '.uniqid('', true), $companyId, $root->getId());

        $node->moveTo(null, $this->policy);
        $this->repository->add($node);

        $reloaded = $this->repository->findOneById($node->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getParentId());
    }
}
