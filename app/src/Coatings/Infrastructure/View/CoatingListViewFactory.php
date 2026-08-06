<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\View;

use App\Coatings\Application\DTO\Tags\TagDTOTransformer;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Helper\QueryParams;
use Symfony\Component\HttpFoundation\Request;

/**
 * Собирает full render-payload шаблона списка покрытий. Тянет производителей и
 * выбранные теги, подмешивает пресеты и echo-back значений формы. Экшен остаётся
 * тонким: собрал фильтр → диспатч → отдал build(...) в render.
 */
final class CoatingListViewFactory
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly TagRepositoryInterface $coatingTagRepository,
        private readonly TagDTOTransformer $coatingTagDTOTransformer,
        private readonly QueryParams $query,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Request $request, GetPagedCoatingsQueryResult $result, ?string $error): array
    {
        $manufacturers = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        $tagIds = $this->query->stringCollection($request, 'tagIds');
        $selectedTags = $this->coatingTagDTOTransformer->fromEntityList(
            $this->coatingTagRepository->findByIds($tagIds),
        );

        $search = $request->query->get('search');
        $thermEnvRaw = $request->query->get('thermEnv');
        $sortRaw = $request->query->get('sort');

        // Preserved: всё из URL, кроме того, что форма рендерит отдельно.
        $preservedParams = array_diff_key(
            $request->query->all(),
            array_flip(['search', 'page', 'partial']),
        );

        return [
            'search' => is_string($search) ? $search : '',
            'selectedManufacturerIds' => $this->query->stringCollection($request, 'manufacturerIds'),
            'selectedTags' => $selectedTags,
            'selectedBaseValues' => $this->query->stringCollection(
                $request,
                'baseValues',
                static fn (string $v): bool => null !== CoatingBase::tryFrom($v),
                unique: true,
            ),
            'manufacturers' => $manufacturers->manufacturers,
            'result' => $result,
            'error' => $error,
            'coatingBases' => CoatingBase::cases(),
            'appMinTempPresets' => CoatingRangePresets::appMinTemp(),
            'volumeSolidPresets' => CoatingRangePresets::volumeSolid(),
            'minRecoat20Presets' => CoatingRangePresets::minRecoat20(),
            'maxRecoat20Presets' => CoatingRangePresets::maxRecoat20(),
            'appMinTempFrom' => $this->query->nullableInt($request, 'appMinTempFrom'),
            'appMinTempTo' => $this->query->nullableInt($request, 'appMinTempTo'),
            'volumeSolidFrom' => $this->query->nullableInt($request, 'volumeSolidFrom'),
            'volumeSolidTo' => $this->query->nullableInt($request, 'volumeSolidTo'),
            'minRecoat20From' => $this->query->nullableInt($request, 'minRecoat20From'),
            'minRecoat20To' => $this->query->nullableInt($request, 'minRecoat20To'),
            'maxRecoat20From' => $this->query->nullableInt($request, 'maxRecoat20From'),
            'maxRecoat20To' => $this->query->nullableInt($request, 'maxRecoat20To'),
            'thermTemp' => $this->query->nullableInt($request, 'thermTemp'),
            'thermEnv' => (is_string($thermEnvRaw) ? ThermalEnvironment::tryFrom($thermEnvRaw) : null)?->value,
            'thermIncludingPeak' => (bool) $request->query->get('thermPeak'),
            'sort' => (is_string($sortRaw) ? CoatingSort::tryFrom($sortRaw) : null) ?? CoatingSort::DEFAULT,
            'sortOptions' => CoatingSort::cases(),
            'preservedParams' => $preservedParams,
        ];
    }
}
