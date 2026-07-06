<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTOTransformer;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingSort;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturersFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Repository\RangeFilter;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/list', name: 'app_cabinet_coating_coating_list', methods: ['GET'])]
class ListAction extends AbstractController
{
    /**
     * Пресеты диапазонов — типовые сценарии одной кнопкой. UI-only: чип
     * ставит слайдер в bounds пресета и submit'ит форму с явными from/to.
     * Никакого «preset key» в query-параметрах — backend принимает голые
     * from/to, и пресеты — просто visual shortcut'ы.
     *
     * @var array<string, array{label: string, from: int, to: int}>
     */
    private const APP_MIN_TEMP_PRESETS = [
        'winter'   => ['label' => 'Зимнее (ниже -5)',    'from' => -30, 'to' => -5],
        'standard' => ['label' => 'Стандартное (-5..+5)', 'from' => -5,  'to' => 5],
        'summer'   => ['label' => 'Летнее (более +5)',   'from' => 5,   'to' => 50],
    ];

    /**
     * @var array<string, array{label: string, from: int, to: int}>
     */
    private const VOLUME_SOLID_PRESETS = [
        'low'    => ['label' => 'Низкий (≤ 40 %)',    'from' => 10, 'to' => 40],
        'medium' => ['label' => 'Средний (40–70 %)',  'from' => 40, 'to' => 70],
        'high'   => ['label' => 'Высокий (≥ 70 %)',   'from' => 70, 'to' => 100],
    ];

    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CoatingTagRepositoryInterface $coatingTagRepository,
        private readonly CoatingTagDTOTransformer $coatingTagDTOTransformer,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $manufacturerIds = new StringCollection(...$request->query->all('manufacturerIds'));
        $tagIds = new StringCollection(...$request->query->all('tagIds'));
        // baseValues[] из URL: валидируем через CoatingBase::tryFrom, отсеиваем
        // мусор. Дедуп через array_unique — юзер может кликнуть один и тот же
        // чекбокс дважды в разных местах шторки.
        $baseValuesRaw = array_values(array_filter(
            $request->query->all('baseValues'),
            static fn($v): bool => is_string($v) && CoatingBase::tryFrom($v) !== null,
        ));
        $baseValues = new StringCollection(...array_unique($baseValuesRaw));
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
        $pager = Pager::fromPage($page, $limit);

        $appMinTempFrom  = $this->nullableInt($request->query->get('appMinTempFrom'));
        $appMinTempTo    = $this->nullableInt($request->query->get('appMinTempTo'));
        $volumeSolidFrom = $this->nullableInt($request->query->get('volumeSolidFrom'));
        $volumeSolidTo   = $this->nullableInt($request->query->get('volumeSolidTo'));

        // Температура эксплуатации: одно число + среда (сухое/погружение) +
        // галка «включая пик». Фасет активен, только когда заданы и temp, и env
        // (см. CoatingsFilter::hasThermalFacet). Если пустое — просто уйдут null'ы,
        // CoatingFinder их проигнорирует.
        $thermTemp = $this->nullableInt($request->query->get('thermTemp'));
        $thermEnvRaw = $request->query->get('thermEnv');
        $thermEnv = is_string($thermEnvRaw) ? ThermalEnvironment::tryFrom($thermEnvRaw) : null;
        $thermIncludingPeak = (bool) $request->query->get('thermPeak');

        $sortRaw = $request->query->get('sort');
        $sort = (is_string($sortRaw) ? CoatingSort::tryFrom($sortRaw) : null) ?? CoatingSort::DEFAULT;

        $manufacturersResult = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        // Загружаем выбранные теги как DTO (id + title + type) — шаблону нужны
        // title'ы для чипов, а из URL приходят только id. Если id несуществующий —
        // просто выпадает (findByIds его дропает).
        $selectedTags = $this->coatingTagDTOTransformer->fromEntityList(
            $this->coatingTagRepository->findByIds($tagIds),
        );

        $error = null;
        try {
            $filter = new CoatingsFilter(
                search: SearchQuery::tryFromString(is_string($search) ? $search : null),
                manufacturerIds: $manufacturerIds,
                pager: $pager,
                applicationMinTemp: RangeFilter::tryFromNullable($appMinTempFrom, $appMinTempTo),
                volumeSolid: RangeFilter::tryFromNullable($volumeSolidFrom, $volumeSolidTo),
                tagIds: $tagIds,
                thermalTemperature: $thermTemp,
                thermalEnvironment: $thermEnv,
                thermalIncludingPeak: $thermIncludingPeak,
                sort: $sort,
                baseValues: $baseValues,
            );
            $result = $this->queryBus->execute(new GetPagedCoatingsQuery($filter));
        } catch (AppException $e) {
            $error = $e->getMessage();
            $result = new GetPagedCoatingsQueryResult([], $pager);
        }

        // Infinite scroll: если это AJAX-догрузка next-page, отдаём голый
        // partial с карточками (без layout'a, header'a, поисковой формы и т.д.).
        // Stimulus infinite-list-контроллер парсит HTML и append'ит в DOM.
        if ((bool) $request->query->get('partial', false)) {
            return $this->render('admin/coating/coating/_coating_cards_batch.html.twig', [
                'coatings' => $result->coatings,
                'canEdit' => $this->isGranted('ROLE_ADMIN'),
                'selectedTagIdList' => $tagIds->getList(),
            ]);
        }

        // Preserved-параметры формы: сохраняем всё, что пришло в URL, чтобы submit
        // не сбросил активные фасеты. Исключаем то, что форма рендерит отдельно:
        //  - search — свой visible <input>;
        //  - page/partial — вычисляются заново на бэке.
        // Twig ходит одним циклом, различая scalar/array через `is iterable`.
        $preservedParams = array_diff_key(
            $request->query->all(),
            array_flip(['search', 'page', 'partial']),
        );

        return $this->render('admin/coating/coating/index.html.twig', [
            'search' => $search ?? '',
            'selectedManufacturerIds' => $manufacturerIds,
            // list<CoatingTagDTO> с id+title+type; id'ы для URL-toggle
            // читаются через |map(t => t.id) в шаблоне.
            'selectedTags' => $selectedTags,
            'selectedBaseValues' => $baseValues,
            'manufacturers' => $manufacturersResult->manufacturers,
            'result' => $result,
            'error' => $error,
            'coatingBases' => CoatingBase::cases(),
            'appMinTempPresets'   => self::APP_MIN_TEMP_PRESETS,
            'volumeSolidPresets'  => self::VOLUME_SOLID_PRESETS,
            'appMinTempFrom'   => $appMinTempFrom,
            'appMinTempTo'     => $appMinTempTo,
            'volumeSolidFrom'  => $volumeSolidFrom,
            'volumeSolidTo'    => $volumeSolidTo,
            'thermTemp'         => $thermTemp,
            'thermEnv'          => $thermEnv?->value,
            'thermIncludingPeak' => $thermIncludingPeak,
            'sort'              => $sort,
            'sortOptions'       => CoatingSort::cases(),
            'preservedParams'   => $preservedParams,
        ]);
    }

    private function nullableInt(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        return (int) $raw;
    }
}
