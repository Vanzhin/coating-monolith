<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Repository;

use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Subscription> */
class SubscriptionRepository extends ServiceEntityRepository implements SubscriptionRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    public function save(Subscription $subscription): void
    {
        $this->getEntityManager()->persist($subscription);
        $this->getEntityManager()->flush();
    }

    public function findForUser(string $userUlid): array
    {
        return array_values($this->findBy(['userUlid' => $userUlid]));
    }

    public function isEnabled(string $userUlid, NotificationType $type, NotificationChannel $channel): bool
    {
        $row = $this->findOneBy(['userUlid' => $userUlid, 'type' => $type, 'channel' => $channel]);

        return null !== $row && $row->isEnabled();
    }
}
