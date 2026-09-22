<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetProjectsByIds;

use App\Reports\Application\DTO\Projects\ProjectDTOTransformer;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Гидрация чипов фасета «Проект»: {id,title} по списку id. В URL лежат только id, названия
 * дотягиваются отсюда. Зеркалит suggest, но резолвит по id.
 */
final readonly class GetProjectsByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProjectRepositoryInterface $repository,
        private ProjectDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProjectsByIdsQuery $query): GetProjectsByIdsQueryResult
    {
        return new GetProjectsByIdsQueryResult(
            $this->transformer->fromEntityList($this->repository->findByIds($query->ids)),
        );
    }
}
