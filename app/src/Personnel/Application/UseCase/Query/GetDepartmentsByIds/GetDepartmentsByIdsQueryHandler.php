<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetDepartmentsByIds;

use App\Personnel\Application\DTO\Department\DepartmentDTOTransformer;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Гидрация чипов (id → {id,title}), напр. выбранного родителя/отдела в форме. Зеркалит
 * suggest, но резолвит по id, не по строке.
 */
final readonly class GetDepartmentsByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private DepartmentRepositoryInterface $repository,
        private DepartmentDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetDepartmentsByIdsQuery $query): GetDepartmentsByIdsQueryResult
    {
        return new GetDepartmentsByIdsQueryResult(
            $this->transformer->fromEntityList($this->repository->findByIds($query->ids)),
        );
    }
}
