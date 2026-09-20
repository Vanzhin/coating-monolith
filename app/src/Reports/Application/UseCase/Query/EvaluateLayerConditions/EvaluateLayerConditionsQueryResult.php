<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\EvaluateLayerConditions;

use App\Reports\Domain\Hint\LayerWarning;

final readonly class EvaluateLayerConditionsQueryResult
{
    /**
     * @param list<LayerWarning> $warnings
     */
    public function __construct(
        public array $warnings,
        public ?float $dewPoint = null,
    ) {
    }
}
