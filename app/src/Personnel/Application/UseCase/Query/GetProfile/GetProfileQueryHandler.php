<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfile;

use App\Personnel\Application\DTO\Profile\ProfileDTOTransformer;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProfileQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProfileQuery $query): GetProfileQueryResult
    {
        $profile = $this->repository->findOneById($query->id);

        return new GetProfileQueryResult(
            null !== $profile ? $this->transformer->fromEntity($profile) : null,
        );
    }
}
