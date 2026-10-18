<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetProfileCompliance;

use App\Compliance\Application\DTO\ProfileCompliance\ProfileComplianceDTOTransformer;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/** Read-side карточки учёта: obligation-строки — из read-модели проекции, не агрегата. */
final readonly class GetProfileComplianceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ObligationProjectionRepositoryInterface $repository,
        private ProfileComplianceDTOTransformer $transformer,
    ) {
    }

    public function __invoke(GetProfileComplianceQuery $query): GetProfileComplianceQueryResult
    {
        $rows = $this->repository->findForProfiles(new StringCollection($query->profileId));
        if ([] === $rows) {
            return new GetProfileComplianceQueryResult(null);
        }

        return new GetProfileComplianceQueryResult(
            $this->transformer->fromRows($rows, $query->profileId, new \DateTimeImmutable()),
        );
    }
}
