<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ColorRepository extends ServiceEntityRepository implements ColorRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Color::class);
    }

    public function add(Color $color): void
    {
        $this->getEntityManager()->persist($color);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Color
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findOneByNameAndHex(string $name, string $hex): ?Color
    {
        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) = LOWER(:name)')
            ->andWhere('c.hex = :hex')
            ->setParameter('name', $name)
            ->setParameter('hex', $hex)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByIds(StringCollection $ids): array
    {
        if (0 === $ids->count()) {
            return [];
        }

        return $this->findBy(['id' => $ids->getList()]);
    }

    public function suggest(string $query, int $limit): array
    {
        $needle = mb_strtolower(trim($query));
        if ('' === $needle) {
            return [];
        }

        $like = '%'.$needle.'%';

        return $this->createQueryBuilder('c')
            ->where('LOWER(c.name) LIKE :like')
            ->orWhere('LOWER(c.ral) LIKE :like')
            ->setParameter('like', $like)
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
