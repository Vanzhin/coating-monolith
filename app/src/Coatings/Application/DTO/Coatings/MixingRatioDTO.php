<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Coatings;

/**
 * Транспортный DTO для MixingRatio. Ни поведения, ни инвариантов — они в доменном VO
 * (≥2 компонента, >0, ≤2 знака, хотя бы одна база, равное число компонентов при обеих
 * базах). Мэппер/хендлер гидрирует VO и получает AppException, если набор невалиден.
 */
class MixingRatioDTO
{
    /** @var list<float>|null части по объёму */
    public ?array $volume = null;

    /** @var list<float>|null части по массе */
    public ?array $mass = null;
}
