<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Domain\Repository\PaginationResult;

interface CoatingRepositoryInterface
{
    public function add(Coating $coating): void;

    public function remove(Coating $coating): void;

    public function findByFilter(CoatingsFilter $filter): PaginationResult;

}