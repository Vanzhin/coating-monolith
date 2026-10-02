<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfilesByIds;

use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\DTO\Profile\ProfileDTOTransformer;
use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProfilesByIdsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProfilesByIdsQuery $query): GetProfilesByIdsQueryResult
    {
        return new GetProfilesByIdsQueryResult(array_map(
            fn (Profile $p): ProfileDTO => $this->transformer->fromEntity($p),
            $this->repository->findByIds($query->ids),
        ));
    }
}
