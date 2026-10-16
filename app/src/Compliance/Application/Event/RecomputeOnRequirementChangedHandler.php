<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Domain\Event\RequirementChanged;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Норма изменилась → пересобрать проекции учёта людей на покрытых должностях. Выдача идёт прямо в журнал
 * (черновиков карточек больше нет), поэтому ничего заводить не нужно — только пересборка.
 */
final readonly class RecomputeOnRequirementChangedHandler implements EventHandlerInterface
{
    public function __construct(
        private ComplianceProjectionRebuilder $rebuilder,
    ) {
    }

    public function __invoke(RequirementChanged $event): void
    {
        $this->rebuilder->rebuild(requirementIds: new StringCollection($event->requirementId));
    }
}
