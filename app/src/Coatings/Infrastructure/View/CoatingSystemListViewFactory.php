<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\View;

use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceFacetsRegistry;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Facet\FacetOption;
use App\Coatings\Domain\Repository\CoatingSystemSort;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Full render-payload списка систем покрытий. Echo-back значений формы + опции
 * селектов + обратная конвертация минуты→часы для слайдера времени нанесения.
 */
final class CoatingSystemListViewFactory
{
    private const MINUTES_PER_HOUR = 60;
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly QueryParams $query,
        private readonly ComplianceFacetsRegistry $facetsRegistry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, SearchCoatingSystemsQueryResult $result): array
    {
        $standard = ComplianceStandard::tryFrom((string) $request->query->get('standard', ''));
        $category = $request->query->get('category') ?: null;
        $durability = $request->query->get('durability') ?: null;

        $substrates = array_values(array_filter(array_map(
            static fn (mixed $v): ?Substrate => Substrate::tryFrom((string) $v),
            $request->query->all('substrates'),
        )));

        $environment = EnvironmentType::tryFrom((string) $request->query->get('environment', ''));

        $tagIds = $this->query->stringCollection($request, 'tagIds');
        $coatingIds = $this->query->stringCollection(
            $request,
            'coatingIds',
            static fn (string $id): bool => Uuid::isValid($id),
        );

        $applicationMinTemp = $this->query->intRange($request, 'applicationMinTempFrom', 'applicationMinTempTo');
        $minAppTime = $this->query->intRange($request, 'minApplicationTimeAt20From', 'minApplicationTimeAt20To', self::MINUTES_PER_HOUR);

        $thermEnvRaw = $request->query->get('thermEnv');

        $facets = null !== $standard ? $this->facetsRegistry->facetsFor($standard) : null;

        return [
            'complianceFacets' => $facets,
            'categoryTitle' => self::optionTitle($facets?->primaryOptions() ?? [], $category),
            'durabilityTitle' => self::optionTitle($facets?->secondaryOptions() ?? [], $durability),
            'items' => $result->items,
            'total' => $result->total,
            'q' => trim((string) $request->query->get('q', '')),
            'substrates' => $substrates,
            'environment' => $environment,
            'standard' => $standard,
            'category' => $category,
            'durability' => $durability,
            // У выбранной категории есть вторая ось (долговечность)? У CX/Im4 её нет —
            // блок оси скрываем, значение долговечности игнорируем.
            'durabilityAxisEnabled' => null === $category || null === $facets || $facets->hasSecondaryAxis($category),
            'tagIds' => $tagIds->getList(),
            'coatingIds' => $coatingIds->getList(),
            'applicationMinTemp' => $applicationMinTemp,
            'minApplicationTimeAt20Hours' => $this->rangeToHours($minAppTime),
            'sort' => CoatingSystemSort::tryFrom((string) $request->query->get('sort', '')) ?? CoatingSystemSort::DEFAULT,
            'hasDocuments' => (string) $request->query->get('hasDocuments', ''),
            'thermTemp' => $this->query->nullableInt($request, 'thermTemp'),
            'thermEnv' => (is_string($thermEnvRaw) ? ThermalEnvironment::tryFrom($thermEnvRaw) : null)?->value,
            'thermIncludingPeak' => (bool) $request->query->get('thermPeak'),
            'page' => max(1, (int) $request->query->get('page', 1)),
            'perPage' => self::DEFAULT_LIMIT,
            'sortOptions' => CoatingSystemSort::cases(),
            'substrateOptions' => Substrate::cases(),
            'environmentOptions' => EnvironmentType::cases(),
            'standardOptions' => ComplianceStandard::cases(),
            'thermEnvOptions' => ThermalEnvironment::cases(),
        ];
    }

    /**
     * @param list<FacetOption> $options
     */
    private static function optionTitle(array $options, ?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        foreach ($options as $option) {
            if ($option->value === $value) {
                return $option->title;
            }
        }

        return $value;
    }

    /** @return array{from: int, to: int}|null */
    private function rangeToHours(?RangeFilter $range): ?array
    {
        if (null === $range) {
            return null;
        }

        return [
            'from' => null !== $range->from ? (int) round($range->from / self::MINUTES_PER_HOUR) : 0,
            'to' => null !== $range->to ? (int) round($range->to / self::MINUTES_PER_HOUR) : 0,
        ];
    }
}
