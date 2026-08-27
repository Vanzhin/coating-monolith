<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetPagedIssuers;

use App\Certificates\Application\DTO\Issuers\IssuerDTOTransformer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedIssuersQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private IssuerRepositoryInterface $repository,
        private IssuerDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetPagedIssuersQuery $query): GetPagedIssuersQueryResult
    {
        $paginator = $this->repository->findByFilter($query->filter);
        $issuers = $this->transformer->fromEntityList($paginator->items);
        $pager = new Pager(
            $query->filter->pager->page,
            $query->filter->pager->perPage,
            $paginator->total,
        );

        return new GetPagedIssuersQueryResult($issuers, $pager);
    }
}
