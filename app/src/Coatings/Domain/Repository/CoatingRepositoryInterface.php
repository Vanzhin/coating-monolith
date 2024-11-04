<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Coating\Coating;

interface CoatingRepositoryInterface
{
    public function add(Coating $coating): void;

    public function findOneByTitle(string $title): ?Coating;

}