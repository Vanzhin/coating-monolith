<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetReport;

use App\Reports\Application\DTO\Reports\ReportDTOTransformer;
use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class GetReportQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportDTOTransformer $transformer,
        private ReportAccessControl $access,
    ) {
    }

    public function __invoke(GetReportQuery $query): GetReportQueryResult
    {
        $report = $this->repository->findOneById($query->id);
        if (null === $report) {
            return new GetReportQueryResult(null);
        }
        if (!$this->access->canEdit($report)) {
            throw new ForbiddenException();
        }

        return new GetReportQueryResult($this->transformer->fromEntity($report));
    }
}
