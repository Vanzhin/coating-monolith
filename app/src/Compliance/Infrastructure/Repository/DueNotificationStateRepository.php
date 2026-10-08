<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Entity\DueNotificationState;
use App\Compliance\Domain\Repository\DueNotificationStateRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<DueNotificationState> */
class DueNotificationStateRepository extends ServiceEntityRepository implements DueNotificationStateRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DueNotificationState::class);
    }

    public function findOne(string $profileId, string $obligationKey): ?DueNotificationState
    {
        return $this->findOneBy(['profileId' => $profileId, 'obligationKey' => $obligationKey]);
    }

    public function save(DueNotificationState $state): void
    {
        $this->getEntityManager()->persist($state);
        $this->getEntityManager()->flush();
    }
}
