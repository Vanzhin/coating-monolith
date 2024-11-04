<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetPagedManufacturers;

use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTOTransformer;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

class GetPagedManufacturersQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ManufacturerRepositoryInterface $manufacturerRepository,
        private readonly ManufacturerDTOTransformer      $manufacturerDTOTransformer
    )
    {
    }

    public function __invoke(GetPagedManufacturersQuery $query): GetPagedManufacturersQueryResult
    {
        $paginator = $this->manufacturerRepository->findByFilter($query->filter);
        $manufacturers = $this->manufacturerDTOTransformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total
        );

        return new GetPagedManufacturersQueryResult($manufacturers, $pager);
    }
}
