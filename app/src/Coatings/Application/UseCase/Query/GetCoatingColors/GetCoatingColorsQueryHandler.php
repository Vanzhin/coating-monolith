<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingColors;

use App\Coatings\Application\DTO\Colors\ColorDTOTransformer;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetCoatingColorsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private ColorDTOTransformer $colorTransformer,
    ) {
    }

    public function __invoke(GetCoatingColorsQuery $query): GetCoatingColorsQueryResult
    {
        $coating = $this->coatingRepository->findOneById($query->coatingId);
        if (null === $coating) {
            return new GetCoatingColorsQueryResult(false, []);
        }

        return new GetCoatingColorsQueryResult(
            $coating->isTintable(),
            $this->colorTransformer->fromEntityList($coating->getPossibleColors()),
        );
    }
}
