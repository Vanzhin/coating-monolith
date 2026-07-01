<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQuery;
use App\Shared\Application\Comparison\ComparisonConfig;
use App\Shared\Application\Comparison\ObjectComparator;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/coating/coating/compare',
    name: 'app_cabinet_coating_coating_compare',
    methods: ['GET'],
)]
final class CompareAction extends AbstractController
{
    private const MAX_ITEMS = 4;

    // Поля покрытия, по которым строится сравнение. Подписи и форматирование — в шаблоне.
    private const FIELDS = [
        'title',
        'manufacturer.title',
        'base',
        'volumeSolid',
        'massDensity',
        'pack',
        'thinner',
        'applicationMinTemp',
        'dryingMaxTemp',
        'dftRange.min',
        'dftRange.max',
        'dftRange.tds_dft',
        // Time-related поля (dryToTouch, fullCure, min/maxRecoatingInterval)
        // выведены в отдельную matrix-секцию ниже compare-таблицы —
        // per-subject таблица «Время высыхания», как в preview-модалке.
    ];

    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly ObjectComparator  $comparator,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $idsParam = trim((string) $request->query->get('ids', ''));
        $ids = $idsParam === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $idsParam))));

        if (count($ids) < 2) {
            $this->addFlash('compare_error', 'Выберите минимум 2 покрытия для сравнения.');
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }
        if (count($ids) > self::MAX_ITEMS) {
            $ids = array_slice($ids, 0, self::MAX_ITEMS);
        }

        /** @var \App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetCoatingsByIdsQuery($ids));
        $subjects = $result->coatings;

        if (count($subjects) < 2) {
            $this->addFlash('compare_error', 'Не удалось загрузить выбранные покрытия (возможно, часть была удалена).');
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        $comparison = $this->comparator->compare(new ComparisonConfig(self::FIELDS), ...$subjects);

        return $this->render('admin/coating/coating/compare.html.twig', [
            'subjects'   => $subjects,
            'comparison' => $comparison,
            'fields'     => self::FIELDS,
        ]);
    }
}
