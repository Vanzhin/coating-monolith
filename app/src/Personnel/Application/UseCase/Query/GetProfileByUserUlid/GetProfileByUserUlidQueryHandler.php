<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileByUserUlid;

use App\Personnel\Application\DTO\Profile\ProfileDTOTransformer;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProfileByUserUlidQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProfileByUserUlidQuery $query): GetProfileByUserUlidQueryResult
    {
        $profile = $this->repository->findOneByUserUlid($query->userUlid);

        return new GetProfileByUserUlidQueryResult(
            null !== $profile ? $this->transformer->fromEntity($profile) : null,
        );
    }
}
