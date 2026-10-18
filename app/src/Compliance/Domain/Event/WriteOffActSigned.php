<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Акт списания оформлен (подписан) — количество на фактах уже погашено синхронно, fan-out, у ВСЕХ людей акта
 * ({@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct} кросс-персон: одно требование, несколько
 * профилей). Производное (пересчёт проекции каждого затронутого человека по этому требованию) — в воркере,
 * идемпотентно.
 */
final readonly class WriteOffActSigned implements EventInterface
{
    /** @param list<string> $profileIds Все профили, затронутые актом ({@see WriteOffAct::profileIds}). */
    public function __construct(
        public string $requirementId,
        public array $profileIds,
    ) {
    }
}
