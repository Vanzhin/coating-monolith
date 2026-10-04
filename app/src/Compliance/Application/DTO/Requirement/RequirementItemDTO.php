<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Requirement;

/**
 * Позиция требования для формы/показа. Типа не несёт — он на требовании. cadenceNumber/amount/unit — для
 * полей ввода; *Label — для показа. Количество заполнено только у материального требования.
 */
final class RequirementItemDTO
{
    public string $label;
    public string $cadenceKind;
    public ?int $cadenceNumber = null;
    public ?string $cadenceUnit = null;
    public string $cadenceLabel;
    public ?float $amount = null;
    public ?string $unit = null;
    public string $quantityLabel = '';
    public string $basis;
}
