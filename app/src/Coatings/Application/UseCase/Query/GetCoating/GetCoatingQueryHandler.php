<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoating;

use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

readonly class GetCoatingQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface $coatingRepository,
        private CoatingDTOTransformer $coatingDTOTransformer,
        private CoatingSystemRepositoryInterface $systemRepository,
    ) {
    }

    public function __invoke(GetCoatingQuery $query): GetCoatingQueryResult
    {
        $coating = $this->coatingRepository->findOneById($query->coatingId);
        if (null === $coating) {
            return new GetCoatingQueryResult(null);
        }

        $dto = $this->coatingDTOTransformer->fromEntity($coating);

        $byCoating = $this->systemRepository->findSystemTitlesByCoatingIds(new StringCollection($dto->id));
        $dto->systems = array_map(
            static fn (array $row) => new CoatingSystemTitleDTO($row['id'], $row['title']),
            $byCoating[$dto->id] ?? [],
        );

        return new GetCoatingQueryResult($dto);
    }
}
