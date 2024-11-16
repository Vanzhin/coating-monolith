<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CoatingTagRepository extends ServiceEntityRepository implements CoatingTagRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoatingTag::class);
    }

    public function add(CoatingTag $coatingTag): void
    {
        // TODO: Implement add() method.
    }

    public function findByTitle(string $title): PaginationResult
    {
        // TODO: Implement findByTitle() method.
    }

    public function findByType(string $type): PaginationResult
    {
        // TODO: Implement findByType() method.
    }

    public function findOneById(string $id): ?CoatingTag
    {
        // TODO: Implement findOneById() method.
    }

    public function findOneByTitleAndType(string $title, ?string $type): ?CoatingTag
    {
        // TODO: Implement findOneByTitleAndType() method.
    }
}