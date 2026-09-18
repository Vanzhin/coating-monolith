<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoredFile> */
final class StoredFileRepository extends ServiceEntityRepository implements StoredFileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoredFile::class);
    }

    public function add(StoredFile $file): void
    {
        $em = $this->getEntityManager();
        $em->persist($file);
        $em->flush();
    }

    public function get(string $id): ?StoredFile
    {
        return $this->find($id);
    }

    public function byOwner(string $purposeKey, string $ownerId): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.purpose = :p')->setParameter('p', $purposeKey)
            ->andWhere('f.ownerId = :o')->setParameter('o', $ownerId)
            ->getQuery()->getResult();
    }

    public function expiredStaged(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.status = :s')->setParameter('s', StoredFile::STATUS_STAGED)
            ->andWhere('f.expiresAt < :now')->setParameter('now', $now)
            ->getQuery()->getResult();
    }

    public function remove(StoredFile $file): void
    {
        $em = $this->getEntityManager();
        $em->remove($file);
        $em->flush();
    }
}
