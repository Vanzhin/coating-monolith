<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Транспортный DTO для ThermalExposureLimits. Ни поведения, ни инвариантов —
 * инварианты живут в доменном VO. Мэппер / хендлер сам гидрирует VO и получает
 * AppException, если DTO содержит невалидный набор.
 */
class ThermalExposureLimitsDTO
{
    public ?int $continuous_min = null;
    public ?int $continuous_max = null;
    public ?int $peak_max = null;
    public ?int $peak_duration_minutes = null;
}
