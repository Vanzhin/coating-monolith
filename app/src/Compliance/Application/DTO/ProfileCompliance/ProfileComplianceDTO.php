<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\ProfileCompliance;

/** Учёт человека для карточки: обязанности со статусом + агрегатный светофор. */
final class ProfileComplianceDTO
{
    public string $profileId;
    public string $worstStatus;
    /** @var list<ObligationRowDTO> */
    public array $obligations = [];
}
