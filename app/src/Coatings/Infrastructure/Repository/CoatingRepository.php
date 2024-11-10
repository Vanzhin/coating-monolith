<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class CoatingRepository extends ServiceEntityRepository implements CoatingRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Coating::class);
    }


    public function add(Coating $coating): void
    {
        // TODO: Implement add() method.
    }

    public function findOneByTitle(string $title): ?Coating
    {
        // TODO: Implement findOneByTitle() method.
    }
}