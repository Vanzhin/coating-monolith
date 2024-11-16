<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating\Specification;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

class UniqueTitleAndTypeCoatingTagSpecification implements SpecificationInterface
{
    public function __construct(private readonly CoatingTagRepositoryInterface $coatingTagRepository)
    {
    }

    public function satisfy(CoatingTag $coatingTag): void
    {
        $exist = $this->coatingTagRepository->findOneByTitleAndType($coatingTag->getTitle(), $coatingTag->getType());
        AssertService::null(
            $exist,
            sprintf('Тэг "%s" уже существует для типа "%s".',
                $coatingTag->getTitle(),
                $coatingTag->getType() ?? 'По умолчанию'
            )
        );
    }

}