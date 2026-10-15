<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\ObligationHistory;

/** Одно событие ленты истории позиции: выдача факта журнала либо списание порции факта. */
final class ObligationHistoryEventDTO
{
    public const TYPE_ISSUED = 'issued';
    public const TYPE_WRITE_OFF = 'write_off';

    public string $type;
    /** @var string Y-m-d */
    public string $date;
    /** Количество с единицей (напр. «10 пар») — у материальной позиции; null у процедуры/инструктажа. */
    public ?string $quantityLabel = null;
    /** Только у выдачи. */
    public ?float $wearPercent = null;
    /** Только у выдачи. */
    public ?string $note = null;
    /** Только у списания — человекочитаемая причина. */
    public ?string $reason = null;
    /** Только у списания — № акта, если указан. */
    public ?string $actNumber = null;
    /** Только у списания — id акта (ссылка на страницу акта). */
    public ?string $actId = null;
    /** Только у списания — акт оформлен (true) или ещё черновик (false). */
    public bool $actSigned = false;
}
