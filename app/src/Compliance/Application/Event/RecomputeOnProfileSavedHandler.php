<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Personnel\Domain\Event\ProfileSaved;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Профиль сохранён (создан/сменил должность) → пересобрать его проекцию учёта по требованиям новой должности.
 * Выдача идёт прямо в журнал (черновиков карточек больше нет) — заводить ничего не нужно. В воркере (async).
 */
final readonly class RecomputeOnProfileSavedHandler implements EventHandlerInterface
{
    public function __construct(
        private ComplianceProjectionRebuilder $rebuilder,
    ) {
    }

    public function __invoke(ProfileSaved $event): void
    {
        $this->rebuilder->rebuild(profileIds: new StringCollection($event->profileId));
    }
}
