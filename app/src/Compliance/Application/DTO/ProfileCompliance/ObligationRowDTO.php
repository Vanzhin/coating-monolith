<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\ProfileCompliance;

/** Строка обязанности человека для карточки/дашборда. Статус вычислен на момент запроса. */
final class ObligationRowDTO
{
    public string $key;
    public string $requirementId;
    public string $requirementName;
    public string $label;
    public string $type;
    public string $cadenceKind;
    public ?int $cadenceNumber = null;
    public ?string $cadenceUnit = null;
    public string $cadenceLabel;
    public string $quantityLabel = '';
    /** Норма для предзаполнения формы: значение и единица (у материальных). */
    public ?string $quantityValue = null;
    public ?string $quantityUnit = null;
    public ?string $lastFulfilledAt = null;
    public ?string $nextDueAt = null;
    public bool $active;
    public string $status;
    /** Канонический 4-бакет (ok/soon/overdue/missing) — тот же резолвер/ладдер, что у дашборда. */
    public string $bucket;
    public string $bucketLabel;
    public string $origin = 'norm'; // norm | personal — персональную (вне нормы) можно убрать из черновика
    /** На руках — у периодической это ПОСЛЕДНЯЯ выдача, не сумма ({@see \App\Compliance\Domain\ReadModel\ObligationProjection::heldQuantity()}). Читатели берут готовым, не пересчитывают. */
    public float $heldQuantity = 0.0;
    /** Прятать количество/«на руках» на экране — инструктаж (периодическая, норма 1 шт). Источник — {@see \App\Compliance\Domain\ReadModel\ObligationProjection::isInstructionLike()}. */
    public bool $hideQuantity = false;
}
