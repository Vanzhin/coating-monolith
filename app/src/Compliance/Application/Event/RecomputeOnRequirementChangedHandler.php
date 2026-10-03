<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\Service\DraftFormationService;
use App\Compliance\Domain\Event\RequirementChanged;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Норма изменилась → пересобрать проекции учёта людей на покрытых должностях, затем завести им черновики
 * карточек по этому требованию (где есть что выдавать и открытого черновика ещё нет).
 */
final readonly class RecomputeOnRequirementChangedHandler implements EventHandlerInterface
{
    public function __construct(
        private ComplianceProjectionRebuilder $rebuilder,
        private DraftFormationService $formation,
    ) {
    }

    public function __invoke(RequirementChanged $event): void
    {
        $this->rebuilder->rebuild(requirementIds: new StringCollection($event->requirementId));
        $this->formation->formForRequirement($event->requirementId, new \DateTimeImmutable());
    }
}
