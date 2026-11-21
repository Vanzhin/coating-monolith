<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use App\Waybills\Domain\Repository\ProfileFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DriverProfile> */
final class DriverProfileRepository extends ServiceEntityRepository implements DriverProfileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DriverProfile::class);
    }

    public function findOneById(string $id): ?DriverProfile
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findByFilter(ProfileFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('p')->orderBy('p.id', 'ASC');
        if (null !== $filter->ownerId) {
            $qb->andWhere('p.ownerId = :ownerId')->setParameter('ownerId', $filter->ownerId);
        }
        if (null !== $filter->pager) {
            $qb->setFirstResult($filter->pager->getOffset())->setMaxResults($filter->pager->getLimit());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(array_values(iterator_to_array($paginator->getIterator())), $paginator->count());
    }

    public function add(DriverProfile $profile): void
    {
        try {
            $this->getEntityManager()->persist($profile);
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException) {
            throw new AppException('Карточка водителя для этого пользователя уже существует.');
        }
    }
}
