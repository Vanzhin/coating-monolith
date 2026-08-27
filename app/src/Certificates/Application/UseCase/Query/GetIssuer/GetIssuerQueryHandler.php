<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetIssuer;

use App\Certificates\Application\DTO\Issuers\IssuerDTOTransformer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetIssuerQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private IssuerRepositoryInterface $repository,
        private IssuerDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetIssuerQuery $query): GetIssuerQueryResult
    {
        $issuer = $this->repository->findOneById($query->id);

        return new GetIssuerQueryResult(null !== $issuer ? $this->transformer->fromEntity($issuer) : null);
    }
}
