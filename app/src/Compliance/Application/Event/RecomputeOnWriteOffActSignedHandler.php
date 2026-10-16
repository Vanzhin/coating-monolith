<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Domain\Event\WriteOffActSigned;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Акт списания оформлен → пересобрать проекцию КАЖДОГО затронутого актом человека по этому требованию (единый
 * сервис пересчёта, fan-out по {@see WriteOffActSigned::$profileIds} — акт кросс-персон). Дефицит после
 * списания виден в журнале — отдельную выдачу-черновик заводить не нужно. В воркере (async), идемпотентно:
 * пересчёт — чистая пересборка из фактов.
 */
final readonly class RecomputeOnWriteOffActSignedHandler implements EventHandlerInterface
{
    public function __construct(
        private ComplianceProjectionRebuilder $rebuilder,
    ) {
    }

    public function __invoke(WriteOffActSigned $event): void
    {
        $this->rebuilder->rebuild(
            requirementIds: new StringCollection($event->requirementId),
            profileIds: new StringCollection(...$event->profileIds),
        );
    }
}
