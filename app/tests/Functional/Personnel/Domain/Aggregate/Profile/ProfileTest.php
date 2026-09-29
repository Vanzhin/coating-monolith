<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Domain\Aggregate\Profile;

use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Personnel\Domain\Aggregate\Profile\Specification\ProfileSpecification;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\ValueObject\Reference;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProfileTest extends KernelTestCase
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

    private function create(
        ?string $userUlid = null,
        ?\DateTimeImmutable $now = null,
        ?string $personnelNumber = 'PN-001',
        ?\DateTimeImmutable $hiredAt = null,
    ): Profile {
        $profile = new Profile(
            UuidService::generate(),
            $userUlid ?? UuidService::generateUlid(),
            new FullName('Иванов', 'Иван', 'Иванович'),
            new Reference(UuidService::generate(), 'Маляр'),
            new Reference(UuidService::generate(), 'ООО Ромашка'),
            new Reference(UuidService::generate(), 'Цех №1'),
            new Sizes('52', '42', '58', null, null, '9', '180', Gender::Male),
            $personnelNumber,
            $hiredAt,
            $this->specification,
            $now ?? new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
        $this->repository->add($profile);
        $this->createdIds[] = $profile->getId();

        return $profile;
    }

    public function test_create_persists_and_is_readable_by_id_and_user_ulid(): void
    {
        $userUlid = UuidService::generateUlid();
        $created = $this->create($userUlid);

        $byId = $this->repository->findOneById($created->getId());
        self::assertNotNull($byId);
        self::assertSame($created->getId(), $byId->getId());
        self::assertSame($userUlid, $byId->getUserUlid());
        self::assertSame('Иванов', $byId->getFullName()->lastName);
        self::assertSame('PN-001', $byId->getPersonnelNumber());
        self::assertSame('52', $byId->getSizes()->clothing);

        $byUlid = $this->repository->findOneByUserUlid($userUlid);
        self::assertNotNull($byUlid);
        self::assertSame($created->getId(), $byUlid->getId());
    }

    public function test_second_profile_for_same_user_ulid_is_rejected(): void
    {
        $userUlid = UuidService::generateUlid();
        $this->create($userUlid);

        $this->expectException(AppException::class);
        $this->create($userUlid);
    }

    public function test_empty_user_ulid_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Profile(
            UuidService::generate(),
            '   ',
            new FullName('Иванов', 'Иван'),
            new Reference(UuidService::generate(), 'Маляр'),
            new Reference(UuidService::generate(), 'Организация'),
            new Reference(UuidService::generate(), 'Отдел'),
            Sizes::empty(),
            null,
            null,
            $this->specification,
            new \DateTimeImmutable(),
        );
    }

    public function test_malformed_user_ulid_is_rejected(): void
    {
        $this->expectException(AppException::class);
        new Profile(
            UuidService::generate(),
            'not-a-valid-ulid',
            new FullName('Иванов', 'Иван'),
            new Reference(UuidService::generate(), 'Маляр'),
            new Reference(UuidService::generate(), 'Организация'),
            new Reference(UuidService::generate(), 'Отдел'),
            Sizes::empty(),
            null,
            null,
            $this->specification,
            new \DateTimeImmutable(),
        );
    }

    public function test_change_position_updates_reference_and_updated_at(): void
    {
        $now = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $profile = $this->create(now: $now);
        $later = $now->modify('+1 hour');

        $newPosition = new Reference(UuidService::generate(), 'Бригадир');
        $profile->changePosition($newPosition, $later);
        $this->repository->add($profile);

        $reloaded = $this->repository->findOneById($profile->getId());
        self::assertNotNull($reloaded);
        self::assertSame($newPosition->id, $reloaded->getPosition()->id);
        self::assertSame($newPosition->title, $reloaded->getPosition()->title);
        self::assertEquals($later, $reloaded->getUpdatedAt());
        self::assertEquals($now, $reloaded->getCreatedAt());
    }

    public function test_change_organization_and_department_updates_references(): void
    {
        $profile = $this->create();
        $newOrg = new Reference(UuidService::generate(), 'Новая организация');
        $newDept = new Reference(UuidService::generate(), 'Новый отдел');
        $now = new \DateTimeImmutable();

        $profile->changeOrganization($newOrg, $now);
        $profile->changeDepartment($newDept, $now);
        $this->repository->add($profile);

        $reloaded = $this->repository->findOneById($profile->getId());
        self::assertNotNull($reloaded);
        self::assertSame($newOrg->id, $reloaded->getOrganization()->id);
        self::assertSame($newDept->id, $reloaded->getDepartment()->id);
    }

    public function test_change_full_name_and_sizes(): void
    {
        $profile = $this->create();
        $newName = new FullName('Петров', 'Пётр');
        $newSizes = new Sizes('48', shoes: '40');
        $now = new \DateTimeImmutable();

        $profile->changeFullName($newName, $now);
        $profile->changeSizes($newSizes, $now);
        $this->repository->add($profile);

        $reloaded = $this->repository->findOneById($profile->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Петров', $reloaded->getFullName()->lastName);
        self::assertSame('48', $reloaded->getSizes()->clothing);
        self::assertSame('40', $reloaded->getSizes()->shoes);
    }

    public function test_change_hired_at_accepts_null(): void
    {
        $profile = $this->create(hiredAt: new \DateTimeImmutable('2020-05-01'));
        $now = new \DateTimeImmutable();

        $profile->changeHiredAt(null, $now);
        $this->repository->add($profile);

        $reloaded = $this->repository->findOneById($profile->getId());
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getHiredAt());
    }

    public function test_personnel_number_blank_string_becomes_null(): void
    {
        $profile = $this->create(personnelNumber: '   ');

        self::assertNull($profile->getPersonnelNumber());
    }

    public function test_personnel_number_too_long_is_rejected(): void
    {
        $this->expectException(AppException::class);
        $this->create(personnelNumber: str_repeat('9', 51));
    }

    public function test_find_by_ids_returns_requested_profiles(): void
    {
        $a = $this->create();
        $b = $this->create();
        $this->create();

        $result = $this->repository->findByIds(new StringCollection($a->getId(), $b->getId()));

        $ids = array_map(static fn (Profile $p) => $p->getId(), $result);
        self::assertCount(2, $result);
        self::assertContains($a->getId(), $ids);
        self::assertContains($b->getId(), $ids);
    }

    public function test_find_by_ids_returns_empty_array_for_empty_collection(): void
    {
        self::assertSame([], $this->repository->findByIds(new StringCollection()));
    }
}
