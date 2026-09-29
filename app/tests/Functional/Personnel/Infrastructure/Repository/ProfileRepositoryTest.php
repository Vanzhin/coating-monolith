<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Infrastructure\Repository;

use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Personnel\Domain\Aggregate\Profile\Specification\ProfileSpecification;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\Repository\ProfilesFilter;
use App\Personnel\Domain\ValueObject\Reference;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProfileRepositoryTest extends KernelTestCase
{
    private ProfileSpecification $specification;
    private ProfileRepositoryInterface $repository;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->specification = $container->get(ProfileSpecification::class);
        $this->repository = $container->get(ProfileRepositoryInterface::class);
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

    private function create(FullName $fullName, Reference $position, Reference $organization, Reference $department): Profile
    {
        $profile = new Profile(
            UuidService::generate(),
            UuidService::generateUlid(),
            $fullName,
            $position,
            $organization,
            $department,
            Sizes::empty(),
            null,
            null,
            $this->specification,
            new \DateTimeImmutable(),
        );
        $this->repository->add($profile);
        $this->createdIds[] = $profile->getId();

        return $profile;
    }

    public function test_count_by_position_id_counts_only_matching_profiles(): void
    {
        $positionX = new Reference(UuidService::generate(), 'Маляр');
        $positionY = new Reference(UuidService::generate(), 'Сварщик');
        $organization = new Reference(UuidService::generate(), 'Организация');
        $department = new Reference(UuidService::generate(), 'Отдел');

        $this->create(new FullName('Иванов', 'Иван'), $positionX, $organization, $department);

        self::assertSame(1, $this->repository->countByPositionId($positionX->id));
        self::assertSame(0, $this->repository->countByPositionId($positionY->id));
    }

    public function test_count_by_department_id_counts_only_matching_profiles(): void
    {
        $position = new Reference(UuidService::generate(), 'Маляр');
        $organization = new Reference(UuidService::generate(), 'Организация');
        $departmentX = new Reference(UuidService::generate(), 'Цех №1');
        $departmentY = new Reference(UuidService::generate(), 'Цех №2');

        $this->create(new FullName('Иванов', 'Иван'), $position, $organization, $departmentX);

        self::assertSame(1, $this->repository->countByDepartmentId($departmentX->id));
        self::assertSame(0, $this->repository->countByDepartmentId($departmentY->id));
    }

    public function test_find_by_filter_searches_by_full_name_and_paginates(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $position = new Reference(UuidService::generate(), 'Маляр');
        $organization = new Reference(UuidService::generate(), 'Организация');
        $department = new Reference(UuidService::generate(), 'Отдел');

        $created = $this->create(new FullName('Уникальнов-'.$suffix, 'Иван'), $position, $organization, $department);
        $this->create(new FullName('Другой', 'Пётр'), $position, $organization, $department);

        $result = $this->repository->findByFilter(new ProfilesFilter(
            pager: Pager::fromPage(1, 50),
            search: 'Уникальнов-'.$suffix,
        ));

        $ids = array_map(static fn (Profile $p) => $p->getId(), $result->items);
        self::assertContains($created->getId(), $ids);
        self::assertNotContains($this->createdIds[1], $ids);
    }

    public function test_find_by_filter_facets_by_position_id(): void
    {
        $positionX = new Reference(UuidService::generate(), 'Маляр');
        $positionY = new Reference(UuidService::generate(), 'Сварщик');
        $organization = new Reference(UuidService::generate(), 'Организация');
        $department = new Reference(UuidService::generate(), 'Отдел');

        $inFacet = $this->create(new FullName('Первый', 'Иван'), $positionX, $organization, $department);
        $this->create(new FullName('Второй', 'Пётр'), $positionY, $organization, $department);

        $result = $this->repository->findByFilter(new ProfilesFilter(
            pager: Pager::fromPage(1, 50),
            positionIds: new StringCollection($positionX->id),
        ));

        $ids = array_map(static fn (Profile $p) => $p->getId(), $result->items);
        self::assertContains($inFacet->getId(), $ids);
        self::assertCount(1, $result->items);
    }
}
