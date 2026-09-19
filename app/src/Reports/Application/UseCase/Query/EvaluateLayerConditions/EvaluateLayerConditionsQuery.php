<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\EvaluateLayerConditions;

use App\Shared\Application\Query\Query;

/**
 * Подсказки для одного слоя: покрытие (по id из каталога) + фактические замеры. Замеры опциональны —
 * работает и на неполном черновике. Ничего не сохраняет.
 */
final readonly class EvaluateLayerConditionsQuery extends Query
{
    public function __construct(
        public string $coatingId,
        public ?float $dryFilmMean = null,
        public ?float $surfaceTemp = null,
        public ?float $airTemp = null,
        public ?float $humidity = null,
    ) {
    }
}
