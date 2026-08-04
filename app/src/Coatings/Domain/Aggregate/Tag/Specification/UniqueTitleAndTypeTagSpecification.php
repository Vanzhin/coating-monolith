<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Tag\Specification;

use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

class UniqueTitleAndTypeTagSpecification implements SpecificationInterface
{
    public function __construct(private readonly TagRepositoryInterface $coatingTagRepository)
    {
    }

    public function satisfy(Tag $coatingTag): void
    {
        $exist = $this->coatingTagRepository->findOneByTitleAndType($coatingTag->getTitle(), $coatingTag->getType());
        if (null !== $exist && $exist->getId() !== $coatingTag->getId()) {
            AssertService::null(
                $exist,
                sprintf('Тэг "%s" уже существует для типа "%s".',
                    $coatingTag->getTitle(),
                    $coatingTag->getType() ?? 'По умолчанию'
                )
            );
        }
    }
}
