<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating\Specification;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

class UniqueTitleCoatingSpecification implements SpecificationInterface
{
    public function __construct(private readonly CoatingRepositoryInterface $coatingRepository)
    {
    }

    public function satisfy(Coating $coating): void
    {
        $exist = $this->coatingRepository->findOneByTitle($coating->getTitle());
        if ($exist?->getId() !== $coating->getId()) {
            AssertService::null(
                $exist,
                sprintf('Coating with title "%s" already exist.', $coating->getTitle())
            );
        }
    }

}