<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\Service\DraftFormationService;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Personnel\Domain\Event\ProfileSaved;
use App\Shared\Application\Event\EventHandlerInterface;

/**
 * Профиль сохранён (создан/сменил должность) → пересобрать его проекцию учёта и завести черновики карточек
 * по требованиям новой должности (где есть что выдавать и открытого черновика ещё нет). В воркере (async).
 */
final readonly class RecomputeOnProfileSavedHandler implements EventHandlerInterface
{
    public function __construct(
        private ComplianceProjectionRebuilder $rebuilder,
        private ProfileComplianceRepositoryInterface $repository,
        private DraftFormationService $formation,
    ) {
    }

    public function __invoke(ProfileSaved $event): void
    {
        $this->rebuilder->rebuildForProfile($event->profileId);
        $profileCompliance = $this->repository->findByProfile($event->profileId);
        if (null !== $profileCompliance && $this->formation->formForProfile($profileCompliance, new \DateTimeImmutable()) > 0) {
            $this->repository->add($profileCompliance);
        }
    }
}
