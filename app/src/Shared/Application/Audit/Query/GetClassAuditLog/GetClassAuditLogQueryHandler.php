<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Query\GetClassAuditLog;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Application\Audit\AuditLogView;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;

final readonly class GetClassAuditLogQueryHandler implements QueryHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private AuditEntryRepositoryInterface $repository,
        private AuditLogTransformer $transformer,
    ) {
    }

    /** @return list<AuditLogView> */
    public function __invoke(GetClassAuditLogQuery $query): array
    {
        $entries = $this->repository->forClass(
            $query->entityClass,
            $query->actorId,
            Pager::fromPage($query->page, self::PER_PAGE),
        );

        return array_map($this->transformer->view(...), $entries);
    }
}
