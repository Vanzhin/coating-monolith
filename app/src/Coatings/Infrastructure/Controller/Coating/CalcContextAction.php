<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoatingCalcContext\GetCoatingCalcContextQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingCalcContext\GetCoatingCalcContextQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Контекст покрытия для калькуляторов по id: сухой остаток, фасовка, плотность, соотношение —
 * та же форма, что item в suggest. Кормит calc_launcher на странице заполнения: по клику на значок
 * калькулятора у поля слоя тянем данные покрытия этого слоя и засеваем калькулятор. Фетч — только
 * по клику (ленивый).
 */
#[Route(
    path: '/cabinet/coating/coating/{id}/calc-context',
    name: 'app_cabinet_coating_coating_calc_context',
    methods: ['GET'],
)]
final class CalcContextAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $result = $this->queryBus->execute(new GetCoatingCalcContextQuery($id));
        \assert($result instanceof GetCoatingCalcContextQueryResult);

        $coating = $result->coating;
        if (null === $coating) {
            return new JsonResponse(['message' => 'Покрытие не найдено.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'id' => $coating->id,
            'title' => $coating->title,
            'volumeSolid' => $coating->volumeSolid,
            'pack' => $coating->pack,
            'massDensity' => $coating->massDensity,
            'mixingRatio' => null === $coating->mixingRatio
                ? null
                : ['volume' => $coating->mixingRatio->volume, 'mass' => $coating->mixingRatio->mass],
        ]);
    }
}
