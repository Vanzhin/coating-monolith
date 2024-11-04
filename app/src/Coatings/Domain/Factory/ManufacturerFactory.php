<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Factory;

use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;


readonly class ManufacturerFactory
{
    public function __construct(private ManufacturerSpecification $manufacturerSpecification)
    {
    }

    public function create(string $title, ?string $description = null): Manufacturer
    {
        return new Manufacturer($title, $this->manufacturerSpecification, $description);
    }

}