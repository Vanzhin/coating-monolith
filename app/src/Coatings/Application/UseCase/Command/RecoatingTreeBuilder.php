<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
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
            $nodeLabel = $this->labelFor($key);
            $childLabels = array_map(
                fn(RecoatingIntervalTree $child) => $this->labelFor($child->key),
                $children,
            );
            throw new AppException(sprintf(
                'На уровне «%s» заданы правила для (%s), но не указано общее значение для самого уровня «%s». '
                . 'Заполните хотя бы одну точку (с ненулевой длительностью) на этом уровне — иначе для остальных оснований не будет известно значение интервала.',
                $nodeLabel,
                implode(', ', $childLabels),
                $nodeLabel,
            ));
        }

        return new RecoatingIntervalTree($this->buildSeries($node->default), $key, ...$children);
    }

    /**
     * Подпись узла дерева для пользовательских сообщений. Резолвит ключ
     * через существующие enum'ы (EnvironmentType / CoatingBase), не дублируя их.
     */
    private function labelFor(string $key): string
    {
        if ($key === 'default') {
            return 'Общее';
        }

        $env = EnvironmentType::tryFrom($key);
        if ($env !== null) {
            return $env->title();
        }

        $base = CoatingBase::tryFrom(strtoupper($key));
        if ($base !== null) {
            return sprintf('основания %s (%s)', $base->title(), $base->iso());
        }

        return $key;
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
