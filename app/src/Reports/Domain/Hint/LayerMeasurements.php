<?php

declare(strict_types=1);

namespace App\Reports\Domain\Hint;

/**
 * Фактические замеры одного слоя для подсказок. Всё опционально — черновик заполняется по ходу,
 * evaluator пропускает проверки, для которых не хватает данных.
 */
final readonly class LayerMeasurements
{
    public function __construct(
        public ?float $dryFilmMean = null,
        public ?float $surfaceTemp = null,
        public ?float $airTemp = null,
        public ?float $humidity = null,
    ) {
    }
}
