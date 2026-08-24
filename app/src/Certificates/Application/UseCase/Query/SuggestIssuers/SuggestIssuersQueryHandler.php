<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\SuggestIssuers;

use App\Certificates\Application\DTO\Issuers\IssuerDTOTransformer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class SuggestIssuersQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private IssuerRepositoryInterface $repository,
        private IssuerDTOTransformer $transformer,
    ) {
    }

    public function __invoke(SuggestIssuersQuery $query): SuggestIssuersQueryResult
    {
        $issuers = $this->repository->suggest($query->query, $query->limit);

        return new SuggestIssuersQueryResult($this->transformer->fromEntityList($issuers));
    }
}
