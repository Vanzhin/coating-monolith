<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Notification;
use App\Notifications\Domain\Repository\NotificationRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
