<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

/**
 * Снапшот максимальной температуры эксплуатации системы (слабое звено — min по слоям),
 * верхняя граница, отдельно для сухого тепла и погружения × непрерывная/пиковая.
 * null — значение неизвестно (пустая система или у какого-то слоя нет данных в этой среде).
 */
final readonly class OperatingTemperatureSnapshot
{
    public function __construct(
        public ?int $dryHeatContinuousMax,
        public ?int $dryHeatPeakMax,
        public ?int $immersionContinuousMax,
        public ?int $immersionPeakMax,
    ) {
    }
}
