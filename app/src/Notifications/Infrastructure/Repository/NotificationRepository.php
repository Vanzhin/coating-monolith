<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationFilter;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class NotificationRepository extends ServiceEntityRepository implements NotificationRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function add(Notification $notification): void
    {
        $this->getEntityManager()->persist($notification);
        $this->getEntityManager()->flush();
    }

    public function findById(string $id): ?Notification
    {
        return $this->find($id);
    }

    public function findByFilter(NotificationFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.ownerUlid = :owner')
            ->setParameter('owner', $filter->ownerUlid)
            ->orderBy('n.createdAt', 'DESC');

        if (null !== $filter->isRead) {
            $qb->andWhere('n.isRead = :read')->setParameter('read', $filter->isRead);
        }

        $qb->setMaxResults($filter->getPager()->getLimit());
        $qb->setFirstResult($filter->getPager()->getOffset());

        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
    }

    public function countUnread(string $ownerUlid): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.ownerUlid = :owner')
            ->andWhere('n.isRead = false')
            ->setParameter('owner', $ownerUlid)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function markAllReadForOwner(string $ownerUlid): void
    {
        // Bulk-мутация через DBAL (DQL-update в проекте не используется) — как в cache-репозиториях.
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notification SET is_read = true, read_at = now() WHERE owner_id = :owner AND is_read = false',
            ['owner' => $ownerUlid],
        );
    }
}
