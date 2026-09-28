<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPositionsByIds;

use App\Personnel\Application\DTO\Position\PositionDTOTransformer;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Гидрация чипов (id → {id,title}), напр. фасета «Должность» или выбранного значения в форме
 * профиля. В URL/DTO лежат только id, названия дотягиваются отсюда. Зеркалит suggest, но
 * резолвит по id, не по строке.
 */
final readonly class GetPositionsByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private PositionRepositoryInterface $repository,
        private PositionDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPositionsByIdsQuery $query): GetPositionsByIdsQueryResult
    {
        return new GetPositionsByIdsQueryResult(
            $this->transformer->fromEntityList($this->repository->findByIds($query->ids)),
        );
    }
}
