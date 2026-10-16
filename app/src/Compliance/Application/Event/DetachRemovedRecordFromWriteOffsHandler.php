<?php

declare(strict_types=1);

namespace App\Compliance\Application\Event;

use App\Compliance\Domain\Event\FulfillmentRecordRemoved;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Shared\Application\Event\EventHandlerInterface;

/**
 * Факт выдачи удалён → снять его порцию с ВСЕХ черновиков акта списания, где она успела оказаться
 * (findDraftsContainingRecord отбирает только черновики — подписанные акты заморожены, не трогаем).
 * Опустевший после снятия акт (последняя строка была по этому факту) удаляем целиком — пустой черновик без
 * строк не несёт смысла.
 */
final readonly class DetachRemovedRecordFromWriteOffsHandler implements EventHandlerInterface
{
    public function __construct(
        private WriteOffActRepositoryInterface $writeOffActRepo,
    ) {
    }

    public function __invoke(FulfillmentRecordRemoved $event): void
    {
        $drafts = $this->writeOffActRepo->findDraftsContainingRecord($event->recordId);
        foreach ($drafts as $act) {
            $act->removeLinesByRecordIds([$event->recordId]);
            if ([] === $act->lines()) {
                $this->writeOffActRepo->remove($act);
            } else {
                $this->writeOffActRepo->add($act);
            }
        }
    }
}
