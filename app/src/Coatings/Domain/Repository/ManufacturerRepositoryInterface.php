<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Shared\Domain\Repository\PaginationResult;
use App\Skills\Domain\Aggregate\Speciality\SpecialitySkill;

interface ManufacturerRepositoryInterface
{
    public function add(Manufacturer $manufacturer): void;

    public function findOneByTitle(string $title): ?Manufacturer;

    public function findOneById(string $manufacturerId): ?Manufacturer;

    public function findByFilter(ManufacturersFilter $filter): PaginationResult;

    public function remove(Manufacturer $manufacturer): void;

}