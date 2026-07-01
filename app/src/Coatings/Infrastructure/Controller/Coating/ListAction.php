<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQuery;
use App\Coatings\Application\UseCase\Query\GetPagedCoatings\GetPagedCoatingsQueryResult;
use App\Coatings\Application\UseCase\Query\GetPagedManufacturers\GetPagedManufacturersQuery;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\ManufacturersFilter;
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
        'winter'   => ['label' => 'Зимнее',      'from' => -30, 'to' => 5],
        'standard' => ['label' => 'Стандартное', 'from' => 5,   'to' => 25],
        'summer'   => ['label' => 'Летнее',      'from' => 25,  'to' => 50],
    ];

    /**
     * @var array<string, array{label: string, from: int, to: int}>
     */
    private const VOLUME_SOLID_PRESETS = [
        'low'    => ['label' => 'Низкий (≤ 40 %)',    'from' => 10, 'to' => 40],
        'medium' => ['label' => 'Средний (40–70 %)',  'from' => 40, 'to' => 70],
        'high'   => ['label' => 'Высокий (≥ 70 %)',   'from' => 70, 'to' => 100],
    ];

    private const APP_MIN_TEMP_BOUNDS  = ['min' => -30, 'max' => 50];
    private const VOLUME_SOLID_BOUNDS  = ['min' => 10,  'max' => 100];

    public function __construct(
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $manufacturerIds = new StringCollection(...$request->query->all('manufacturerIds'));
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
        $pager = Pager::fromPage($page, $limit);

        $appMinTempFrom  = $this->nullableInt($request->query->get('appMinTempFrom'));
        $appMinTempTo    = $this->nullableInt($request->query->get('appMinTempTo'));
        $volumeSolidFrom = $this->nullableInt($request->query->get('volumeSolidFrom'));
        $volumeSolidTo   = $this->nullableInt($request->query->get('volumeSolidTo'));

        $manufacturersResult = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        $error = null;
        try {
            $filter = new CoatingsFilter(
                search: $search,
                manufacturerIds: $manufacturerIds,
                pager: $pager,
                applicationMinTemp: RangeFilter::tryFromNullable($appMinTempFrom, $appMinTempTo),
                volumeSolid: RangeFilter::tryFromNullable($volumeSolidFrom, $volumeSolidTo),
            );
            $result = $this->queryBus->execute(new GetPagedCoatingsQuery($filter));
        } catch (AppException $e) {
            $error = $e->getMessage();
            $result = new GetPagedCoatingsQueryResult([], $pager);
        }

        return $this->render('admin/coating/coating/index.html.twig', [
            'search' => $search ?? '',
            'selectedManufacturerIds' => $manufacturerIds,
            'manufacturers' => $manufacturersResult->manufacturers,
            'result' => $result,
            'error' => $error,
            'coatingBases' => CoatingBase::cases(),
            'appMinTempPresets'   => self::APP_MIN_TEMP_PRESETS,
            'volumeSolidPresets'  => self::VOLUME_SOLID_PRESETS,
            'appMinTempBounds'    => self::APP_MIN_TEMP_BOUNDS,
            'volumeSolidBounds'   => self::VOLUME_SOLID_BOUNDS,
            'appMinTempFrom'   => $appMinTempFrom,
            'appMinTempTo'     => $appMinTempTo,
            'volumeSolidFrom'  => $volumeSolidFrom,
            'volumeSolidTo'    => $volumeSolidTo,
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
