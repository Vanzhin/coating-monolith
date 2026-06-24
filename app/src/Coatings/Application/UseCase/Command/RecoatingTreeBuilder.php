<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Собирает доменный RecoatingIntervalTree из транспортного RecoatingIntervalTreeDTO.
 * Возвращает null, если узел и все его дети фактически пусты.
 */
final readonly class RecoatingTreeBuilder
{
    public function build(RecoatingIntervalTreeDTO $node, string $key = 'default'): ?RecoatingIntervalTree
    {
        $children = [];
        foreach ($node->branches as $childKey => $childDto) {
            $childTree = $this->build($childDto, (string) $childKey);
            if ($childTree !== null) {
                $children[] = $childTree;
            }
        }

        if ($node->default === [] && $children === []) {
            return null;
        }
        if ($node->default === []) {
            throw new AppException(sprintf(
                'Серия по умолчанию для узла "%s" не может быть пустой, если есть правила перекрытия.',
                $key,
            ));
        }

        return new RecoatingIntervalTree($this->buildSeries($node->default), $key, ...$children);
    }

    /** @param list<DryingTimePointDTO> $points */
    private function buildSeries(array $points): DryingTimeSeries
    {
        return new DryingTimeSeries(...array_map(
            fn(DryingTimePointDTO $p) => new TimeAtTemperature($p->temperature_at, $p->time_in_minutes, $p->is_calculated),
            $points,
        ));
    }
}
