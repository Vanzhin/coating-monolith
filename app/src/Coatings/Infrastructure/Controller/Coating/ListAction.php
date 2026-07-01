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
     * Пресеты диапазонов — типовые сценарии одним кликом. Ключи попадают
     * в query-параметр `appMinTempPreset` / `volumeSolidPreset`, backend
     * маппит в границы RangeFilter'а. Порядок задаёт порядок чипов в UI.
     * Пары {label, from, to} — label используется в шаблоне.
     *
     * @var array<string, array{label: string, from: int, to: int}>
     */
    private const APP_MIN_TEMP_PRESETS = [
        'frost'    => ['label' => 'Морозное (≤ 0)',   'from' => -30, 'to' => 0],
        'cold'     => ['label' => 'Прохлада (5–15)',   'from' => 5,   'to' => 15],
        'standard' => ['label' => 'Стандарт (10–25)',  'from' => 10,  'to' => 25],
        'warm'     => ['label' => 'Тепло (20–50)',     'from' => 20,  'to' => 50],
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
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $search = $request->query->get('search');
        $manufacturerIds = new StringCollection(...$request->query->all('manufacturerIds'));
        $page = $request->query->get('page') ? (int) $request->query->get('page') : null;
        $limit = $request->query->get('limit') ? (int) $request->query->get('limit') : null;
        $pager = Pager::fromPage($page, $limit);

        $appMinTempPreset  = $this->presetKey($request->query->get('appMinTempPreset'), self::APP_MIN_TEMP_PRESETS);
        $volumeSolidPreset = $this->presetKey($request->query->get('volumeSolidPreset'), self::VOLUME_SOLID_PRESETS);

        $appMinTempRange  = $this->presetToRange($appMinTempPreset, self::APP_MIN_TEMP_PRESETS);
        $volumeSolidRange = $this->presetToRange($volumeSolidPreset, self::VOLUME_SOLID_PRESETS);

        $manufacturersResult = $this->queryBus->execute(
            new GetPagedManufacturersQuery(new ManufacturersFilter(null, Pager::fromPage(1, 1000))),
        );

        $error = null;
        try {
            $filter = new CoatingsFilter(
                search: $search,
                manufacturerIds: $manufacturerIds,
                pager: $pager,
                applicationMinTemp: $appMinTempRange,
                volumeSolid: $volumeSolidRange,
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
            'appMinTempPreset'    => $appMinTempPreset,
            'volumeSolidPreset'   => $volumeSolidPreset,
        ]);
    }

    /** Возвращает ключ пресета только если он есть в whitelist, иначе null. */
    private function presetKey(?string $raw, array $presets): ?string
    {
        if ($raw === null || $raw === '' || !isset($presets[$raw])) {
            return null;
        }
        return $raw;
    }

    private function presetToRange(?string $key, array $presets): ?RangeFilter
    {
        if ($key === null) {
            return null;
        }
        $p = $presets[$key];
        return new RangeFilter($p['from'], $p['to']);
    }
}
