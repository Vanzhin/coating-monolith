<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\ProfileCompliance;

/** Строка обязанности человека для карточки/дашборда. Статус вычислен на момент запроса. */
final class ObligationRowDTO
{
    public string $requirementId;
    public string $requirementName;
    public string $label;
    public string $type;
    public string $cadenceLabel;
    public string $quantityLabel = '';
    public ?string $lastFulfilledAt = null;
    public ?string $nextDueAt = null;
    public bool $active;
    public string $status;
}
