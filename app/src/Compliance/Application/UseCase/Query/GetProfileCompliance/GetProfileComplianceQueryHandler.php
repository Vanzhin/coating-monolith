<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetProfileCompliance;

use App\Compliance\Application\DTO\ProfileCompliance\ProfileComplianceDTOTransformer;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;

final readonly class GetProfileComplianceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private ProfileComplianceDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProfileComplianceQuery $query): GetProfileComplianceQueryResult
    {
        $profileCompliance = $this->repository->findByProfile($query->profileId);
        if (null === $profileCompliance) {
            return new GetProfileComplianceQueryResult(null);
        }

        return new GetProfileComplianceQueryResult(
            $this->transformer->fromEntity($profileCompliance, new \DateTimeImmutable()),
        );
    }
}
