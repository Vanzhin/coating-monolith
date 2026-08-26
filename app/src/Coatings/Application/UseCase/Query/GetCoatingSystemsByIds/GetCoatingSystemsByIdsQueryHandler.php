<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingSystemsByIds;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemTitleDTO;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

readonly class GetCoatingSystemsByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(private CoatingSystemRepositoryInterface $repository)
    {
    }

    public function __invoke(GetCoatingSystemsByIdsQuery $query): GetCoatingSystemsByIdsQueryResult
    {
        $systems = $this->repository->findByIds($query->ids);
        $dtos = array_map(
            static fn ($system) => new CoatingSystemTitleDTO($system->getId(), $system->getTitle()),
            $systems,
        );

        return new GetCoatingSystemsByIdsQueryResult(array_values($dtos));
    }
}
