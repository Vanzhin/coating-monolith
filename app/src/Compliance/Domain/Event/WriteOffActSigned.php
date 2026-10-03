<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Акт списания оформлен (подписан) — количество на фактах уже погашено синхронно, а производное (пересчёт
 * проекции учёта этого человека по требованию + черновик выдачи на дефицит) делаем в воркере, идемпотентно.
 */
final readonly class WriteOffActSigned implements EventInterface
{
    public function __construct(
        public string $profileId,
        public string $requirementId,
    ) {
    }
}
