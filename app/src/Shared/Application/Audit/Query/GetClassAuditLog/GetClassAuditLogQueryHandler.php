<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Query\GetClassAuditLog;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Application\Audit\AuditLogView;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Application\Service\AccessControl\AuditAccessControl;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class GetClassAuditLogQueryHandler implements QueryHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private AuditEntryRepositoryInterface $repository,
        private AuditLogTransformer $transformer,
        private AuditAccessControl $access,
    ) {
    }

    /** @return list<AuditLogView> */
    public function __invoke(GetClassAuditLogQuery $query): array
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $entries = $this->repository->forClass(
            $query->entityClass,
            $query->actorId,
            Pager::fromPage($query->page, self::PER_PAGE),
        );

        return array_map($this->transformer->view(...), $entries);
    }
}
