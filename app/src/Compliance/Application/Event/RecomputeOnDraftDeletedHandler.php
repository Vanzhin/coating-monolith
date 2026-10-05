<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\Service\DraftFormationService;
use App\Compliance\Domain\Event\DraftDeleted;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Черновик удалён → пересобрать проекцию этого человека по этому требованию (единый сервис пересчёта) и, если
 * дефицит остался, завести черновик новой выдачи. В воркере (async), идемпотентно: пересчёт — чистая пересборка
 * из фактов, черновик — с гардом «не более одного открытого».
 */
final readonly class RecomputeOnDraftDeletedHandler implements EventHandlerInterface
{
    public function __construct(
        private ComplianceProjectionRebuilder $rebuilder,
        private ProfileComplianceRepositoryInterface $repository,
        private DraftFormationService $formation,
    ) {
    }

    public function __invoke(DraftDeleted $event): void
    {
        $this->rebuilder->rebuild(
            requirementIds: new StringCollection($event->requirementId),
            profileIds: new StringCollection($event->profileId),
        );

        $profileCompliance = $this->repository->findByProfile($event->profileId);
        if (null !== $profileCompliance
            && $this->formation->formForProfileRequirement($profileCompliance, $event->requirementId, new \DateTimeImmutable())) {
            $this->repository->add($profileCompliance);
        }
    }
}
