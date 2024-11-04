<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Manufacturer\Specification;

use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

class UniqueTitleManufacturerSpecification implements SpecificationInterface
{
    public function __construct(private readonly ManufacturerRepositoryInterface $repository)
    {
    }

    public function satisfy(Manufacturer $manufacturer): void
    {
        $exist = $this->repository->findOneByTitle($manufacturer->getTitle());
        AssertService::null(
            $exist,
            sprintf('Производитель с названием "%s" уже существует.', $manufacturer->getTitle())
        );
    }

}