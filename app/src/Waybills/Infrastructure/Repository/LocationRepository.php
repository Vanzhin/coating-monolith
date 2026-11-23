<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Waybills\Domain\Aggregate\Location\Location;
use App\Waybills\Domain\Repository\LocationFilter;
use App\Waybills\Domain\Repository\LocationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Location> */
final class LocationRepository extends ServiceEntityRepository implements LocationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    public function findOneById(string $id): ?Location
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findByFilter(LocationFilter $filter): PaginationResult
    {
        $builder = $this->getEntityManager()->getConnection()->createQueryBuilder();
        $builder->select('id')->from('waybills_location');
        if (null !== $filter->ownerId) {
            $builder->andWhere('owner_id = :ownerId')->setParameter('ownerId', $filter->ownerId);
        }
        if (null !== $filter->search && '' !== trim($filter->search)) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $conditions = array_map(
                static fn (string $field): string => "$field ILIKE :search",
                ['country', 'city', 'city_type', 'street', 'house', 'enterprise', 'latitude::text', 'longitude::text'],
            );
            $builder->andWhere('('.implode(' OR ', $conditions).')')
                ->setParameter('search', '%'.$search.'%');
        }

        $countBuilder = clone $builder;
        $total = (int) $countBuilder->select('COUNT(*)')->executeQuery()->fetchOne();
        if (0 === $total) {
            return new PaginationResult([], 0);
        }

        $builder->orderBy('city', 'ASC')->addOrderBy('street', 'ASC')->addOrderBy('house', 'ASC')->addOrderBy('id', 'ASC');
        if (null !== $filter->pager) {
            $builder->setFirstResult($filter->pager->getOffset())->setMaxResults($filter->pager->getLimit());
        }
        $ids = $builder->executeQuery()->fetchFirstColumn();
        if ([] === $ids) {
            return new PaginationResult([], $total);
        }

        $locations = $this->findBy(['id' => $ids], ['city' => 'ASC', 'street' => 'ASC', 'house' => 'ASC', 'id' => 'ASC']);

        return new PaginationResult(array_values($locations), $total);
    }

    public function add(Location $location): void
    {
        $this->getEntityManager()->persist($location);
        $this->getEntityManager()->flush();
    }
}
