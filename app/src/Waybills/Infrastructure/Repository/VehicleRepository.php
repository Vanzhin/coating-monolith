<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Repository\VehicleFilter;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Vehicle> */
final class VehicleRepository extends ServiceEntityRepository implements VehicleRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Vehicle::class);
    }

    public function add(Vehicle $vehicle): void
    {
        $this->getEntityManager()->persist($vehicle);
        try {
            $this->getEntityManager()->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), '"uniq_waybills_vehicle_registration_number"')) {
                throw new AppException('Автомобиль с таким госномером уже существует.', previous: $exception);
            }
            if (str_contains($exception->getMessage(), '"uniq_waybills_vehicle_driver"')) {
                throw new AppException('Водитель уже находится в другом автомобиле.', previous: $exception);
            }
            throw $exception;
        }
    }

    public function findOneById(string $id, bool $forUpdate = false): ?Vehicle
    {
        if ($forUpdate) {
            return $this->getEntityManager()->find(Vehicle::class, $id, LockMode::PESSIMISTIC_WRITE);
        }

        return $this->findOneBy(['id' => $id]);
    }

    public function findByFilter(VehicleFilter $filter): PaginationResult
    {
        $builder = $this->createQueryBuilder('v')->leftJoin('v.driver', 'driver')->addSelect('driver');
        if (null !== $filter->search && '' !== trim($filter->search)) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim($filter->search));
            $builder->andWhere('LOWER(v.model) LIKE LOWER(:search) OR LOWER(v.registrationNumber) LIKE LOWER(:search)')
                ->setParameter('search', '%'.$search.'%');
        }
        if (null !== $filter->driverOwnerId) {
            $builder->andWhere('driver.ownerId = :driverOwnerId')
                ->setParameter('driverOwnerId', $filter->driverOwnerId);
        }

        $total = (int) (clone $builder)->select('COUNT(v.id)')->getQuery()->getSingleScalarResult();
        /** @var list<Vehicle> $items */
        $items = $builder->orderBy('v.model', 'ASC')->addOrderBy('v.id', 'ASC')
            ->setFirstResult($filter->pager->getOffset())
            ->setMaxResults($filter->pager->getLimit())
            ->getQuery()->getResult();

        return new PaginationResult($items, $total);
    }
}
