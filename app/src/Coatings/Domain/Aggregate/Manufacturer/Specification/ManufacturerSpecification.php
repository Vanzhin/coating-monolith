<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Manufacturer\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class ManufacturerSpecification implements SpecificationInterface
{
    public function __construct(public UniqueTitleManufacturerSpecification $uniqueTitleManufacturerSpecification)
    {
    }

}