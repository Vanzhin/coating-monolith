<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Факт выдачи удалён из журнала человека — каскад на самостоятельный агрегат
 * {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}: порция по этому факту могла уже лежать в
 * ЧЕРНОВИКЕ акта списания (кросс-персон корзина), её нужно снять, иначе акт ссылается на несуществующий факт.
 * Подписанные акты заморожены — их не трогаем ({@see \App\Compliance\Domain\Repository\WriteOffActRepositoryInterface::findDraftsContainingRecord}
 * уже отбирает только черновики).
 *
 * На момент D5 нет прод-пути, который удаляет факт (нет UI/команды) — событие и обработчик защитные,
 * forward-looking, покрыты прямым тестом хендлера.
 */
final readonly class FulfillmentRecordRemoved implements EventInterface
{
    public function __construct(
        public string $profileId,
        public string $recordId,
    ) {
    }
}
