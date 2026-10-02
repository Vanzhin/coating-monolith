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
}
