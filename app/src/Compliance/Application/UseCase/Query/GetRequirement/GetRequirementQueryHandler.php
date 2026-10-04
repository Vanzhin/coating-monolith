<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetRequirement;

use App\Compliance\Application\DTO\Requirement\RequirementDTOTransformer;
use App\Compliance\Application\Service\PositionTitleResolver;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetRequirementQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private RequirementRepositoryInterface $repository,
        private RequirementDTOTransformer $transformer,
        private PositionTitleResolver $positionTitles,
    ) {
    }

    public function __invoke(GetRequirementQuery $query): GetRequirementQueryResult
    {
        $requirement = $this->repository->findOneById($query->id);
        if (null === $requirement) {
            return new GetRequirementQueryResult(null);
        }

        return new GetRequirementQueryResult(
            $this->transformer->fromEntity($requirement, $this->positionTitles->titles($requirement->getPositionIds())),
        );
    }
}
