<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Domain\Event\RequirementChanged;
use App\Shared\Application\Event\EventHandlerInterface;

/** Норма изменилась → пересобрать проекции учёта всех людей на покрытых должностях. */
final readonly class RecomputeOnRequirementChangedHandler implements EventHandlerInterface
{
    public function __construct(private ComplianceProjectionRebuilder $rebuilder)
    {
    }

    public function __invoke(RequirementChanged $event): void
    {
        $this->rebuilder->rebuildForRequirement($event->requirementId);
    }
}
