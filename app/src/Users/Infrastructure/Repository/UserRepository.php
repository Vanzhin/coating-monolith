<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository implements UserRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function add(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function getByUlid(string $ulid): ?User
    {
        return $this->find($ulid);
    }

    public function getByEmail(string $email): ?User
    {
        return $this->findOneBy(['email.value' => strtolower($email)]);
    }

    public function searchByEmail(string $query, int $limit): array
    {
        $needle = trim($query);
        if ('' === $needle) {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('LOWER(u.email.value) LIKE LOWER(:q)')
            ->setParameter('q', '%'.$this->escapeLike($needle).'%')
            ->orderBy('u.email.value', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return $this->findBy(['ulid' => $ids->getList()]);
    }

    private function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }
}
