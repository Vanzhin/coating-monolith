<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Repository;

use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Repository\UsersFilter;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    public function findByFilter(UsersFilter $filter): PaginationResult
    {
        $qb = $this->createQueryBuilder('u')->orderBy('u.email.value', 'ASC');
        if (null !== $filter->email && '' !== trim($filter->email)) {
            $qb->andWhere('LOWER(u.email.value) LIKE LOWER(:email)')
                ->setParameter('email', '%'.$this->escapeLike(trim($filter->email)).'%');
        }
        if (null !== $filter->hasProfile) {
            // Кросс-контекстный подзапрос к Personnel\Profile: осознанное упрощение (не по DDD, но дёшево) —
            // «есть ли у юзера профиль сотрудника». false → только без профиля (пикер привязки).
            $sub = $this->getEntityManager()->createQueryBuilder()
                ->select('1')
                ->from(Profile::class, 'pp')
                ->where('pp.userUlid = u.ulid');
            $qb->andWhere(($filter->hasProfile ? '' : 'NOT ').'EXISTS ('.$sub->getDQL().')');
        }
        if (null !== $filter->pager) {
            $qb->setMaxResults($filter->pager->getLimit());
            $qb->setFirstResult($filter->pager->getOffset());
        }
        $paginator = new Paginator($qb->getQuery());

        return new PaginationResult(iterator_to_array($paginator->getIterator()), $paginator->count());
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
