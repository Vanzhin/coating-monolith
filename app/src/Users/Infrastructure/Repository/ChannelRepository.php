<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Repository\ChannelFilter;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class ChannelRepository extends ServiceEntityRepository implements ChannelRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Channel::class);
    }

    public function add(Channel $channel): void
    {
        $this->getEntityManager()->persist($channel);
        $this->getEntityManager()->flush();
    }

    public function remove(Channel $channel): void
    {
        $this->getEntityManager()->remove($channel);
        $this->getEntityManager()->flush();
    }

    public function findById(string $id): ?Channel
    {
        return $this->find($id);
    }

    public function findOneByOwnerTypeValue(string $ownerId, string $type, string $value): ?Channel
    {
        return $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.owner) = :ownerId')
            ->andWhere('c.type = :type')
            ->andWhere('c.value = :value')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('type', $type)
            ->setParameter('value', $value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByFilter(ChannelFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('uc');
        if ($filter->type) {
            $qb->andWhere($qb->expr()->eq('uc.type', ':type'))
                ->setParameter('type', $filter->type);
        }
        if ($filter->value) {
            $qb->andWhere($qb->expr()->eq('uc.value', ':value'))
                ->setParameter('value', $filter->value);
        }

        $qb->setMaxResults($filter->getPager()->getLimit());
        $qb->setFirstResult($filter->getPager()->getOffset());

        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }
}
