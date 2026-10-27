<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Тепловая нагрузка БЕЗ химии: повышенная температура эксплуатации (нагрев поверхности).
 * maxTemperature — рабочая/постоянная, peakTemperature — пиковая/расчётная, environment —
 * как приходит тепло (ThermalEnvironment value: dry_heat/immersion). Отдельно от химстойкости:
 * бывают кейсы чисто-тепловые, без веществ.
 */
final readonly class ThermalParams
{
    public function __construct(
        public ?int $maxTemperature,
        public ?int $peakTemperature,
        public ?string $environment,
    ) {
    }
}
