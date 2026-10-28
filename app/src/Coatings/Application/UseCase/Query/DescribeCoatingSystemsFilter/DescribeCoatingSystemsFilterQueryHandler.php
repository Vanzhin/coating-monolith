<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\DescribeCoatingSystemsFilter;

use App\Coatings\Application\DTO\CoatingSystems\FilterFacetLabel;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceFacetsRegistry;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\FacetOption;
use App\Shared\Application\Query\QueryHandlerInterface;

/**
 * Примитивы фильтра → подписи (enum::title, категория/долговечность из реестра фасет). Вещества не
 * описываем — у Coatings нет их имён (владеет ChemicalResistance). Порядок осей — как в фасете.
 */
final readonly class DescribeCoatingSystemsFilterQueryHandler implements QueryHandlerInterface
{
    public function __construct(private ComplianceFacetsRegistry $facetsRegistry)
    {
    }

    /**
     * @return list<FilterFacetLabel>
     */
    public function __invoke(DescribeCoatingSystemsFilterQuery $query): array
    {
        $in = $query->input;
        $rows = [];

        if ([] !== $in->substrates) {
            $titles = array_map(static fn (string $v): string => Substrate::tryFrom($v)?->title() ?? $v, $in->substrates);
            $rows[] = new FilterFacetLabel('Подложка', implode(', ', $titles), ['substrates']);
        }

        if (null !== $in->environment) {
            $rows[] = new FilterFacetLabel('Среда', EnvironmentType::tryFrom($in->environment)?->title() ?? $in->environment, ['environment']);
        }

        $standard = null !== $in->standard ? ComplianceStandard::tryFrom($in->standard) : null;
        if (null !== $standard) {
            $rows[] = new FilterFacetLabel('Стандарт', $standard->title(), ['standard']);
            $facets = $this->facetsRegistry->facetsFor($standard);
            if (null !== $in->category) {
                $rows[] = new FilterFacetLabel('Категория коррозивности', $this->optionTitle($facets?->primaryOptions() ?? [], $in->category), ['category']);
            }
            if (null !== $in->durability) {
                $rows[] = new FilterFacetLabel('Долговечность', $this->optionTitle($facets?->secondaryOptions() ?? [], $in->durability), ['durability']);
            }
        }

        if (null !== $in->substanceTemperature) {
            $rows[] = new FilterFacetLabel('Температура у вещества', $in->substanceTemperature.' °C', ['substanceTemperature']);
        }

        if (null !== $in->thermalTemperature) {
            $env = match ($in->thermalEnvironment) {
                'dry_heat' => 'сухой нагрев',
                'immersion' => 'погружение',
                default => null,
            };
            $value = $in->thermalTemperature.' °C'
                .($in->thermalIncludingPeak ? ' (с пиком)' : '')
                .(null !== $env ? ', '.$env : '');
            $rows[] = new FilterFacetLabel('Тепловая нагрузка', $value, ['thermalTemperature', 'thermalEnvironment', 'thermalIncludingPeak']);
        }

        if (null !== $in->applicationMinTempFrom || null !== $in->applicationMinTempTo) {
            $rows[] = new FilterFacetLabel('Температура нанесения', $this->range($in->applicationMinTempFrom, $in->applicationMinTempTo, '°C'), ['applicationMinTempFrom', 'applicationMinTempTo']);
        }

        if (null !== $in->minApplicationTimeFromMinutes || null !== $in->minApplicationTimeToMinutes) {
            $rows[] = new FilterFacetLabel('Время сборки при 20 °C', $this->range($in->minApplicationTimeFromMinutes, $in->minApplicationTimeToMinutes, 'мин'), ['minApplicationTimeFromMinutes', 'minApplicationTimeToMinutes']);
        }

        if (null !== $in->search && '' !== $in->search) {
            $rows[] = new FilterFacetLabel('Поиск по тексту', $in->search, ['search']);
        }

        if (true === $in->hasDocuments) {
            $rows[] = new FilterFacetLabel('С документами', 'да', ['hasDocuments']);
        }

        return $rows;
    }

    /**
     * @param list<FacetOption> $options
     */
    private function optionTitle(array $options, string $value): string
    {
        foreach ($options as $option) {
            if ($option->value === $value) {
                return $option->title;
            }
        }

        return $value;
    }

    private function range(?int $from, ?int $to, string $unit): string
    {
        if (null !== $from && null !== $to) {
            return sprintf('%d…%d %s', $from, $to, $unit);
        }
        if (null !== $from) {
            return sprintf('от %d %s', $from, $unit);
        }

        return sprintf('до %d %s', (int) $to, $unit);
    }
}
