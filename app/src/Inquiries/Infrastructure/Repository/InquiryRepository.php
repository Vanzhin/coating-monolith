<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Inquiry>
 */
class InquiryRepository extends ServiceEntityRepository implements InquiryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Inquiry::class);
    }

    public function add(Inquiry $inquiry): void
    {
        $this->getEntityManager()->persist($inquiry);
        $this->getEntityManager()->flush();
    }

    public function remove(Inquiry $inquiry): void
    {
        $this->getEntityManager()->remove($inquiry);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Inquiry
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }
}
