<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\UseCase\Query\EvaluateLayerConditions\EvaluateLayerConditionsQuery;
use App\Reports\Application\UseCase\Query\EvaluateLayerConditions\EvaluateLayerConditionsQueryResult;
use App\Reports\Domain\Hint\LayerWarning;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Инлайн-подсказки по слою для формы: по покрытию + замерам возвращает мягкие предупреждения
 * (ТСП vs пороги, риск конденсата). Нет покрытия — пусто. Ничего не сохраняет.
 */
#[Route(path: '/cabinet/report/layer-hints', name: 'app_cabinet_report_layer_hints', methods: ['GET'])]
final class LayerHintsAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        // coatingId может быть пуст: точка росы (t воздуха+влажность) считается и без покрытия;
        // предупреждения по порогам/цвету — только когда покрытие выбрано (решает хендлер).
        $coatingId = trim((string) $request->query->get('coatingId', ''));

        $result = $this->queryBus->execute(new EvaluateLayerConditionsQuery(
            coatingId: $coatingId,
            dryFilmMean: $this->num($request, 'dryFilmMean'),
            surfaceTemp: $this->num($request, 'surfaceTemp'),
            airTemp: $this->num($request, 'airTemp'),
            humidity: $this->num($request, 'humidity'),
            color: trim((string) $request->query->get('color', '')) ?: null,
        ));
        \assert($result instanceof EvaluateLayerConditionsQueryResult);

        $items = array_map(
            static fn (LayerWarning $w): array => ['code' => $w->code->value, 'message' => $w->message],
            $result->warnings,
        );

        return new JsonResponse(['warnings' => $items, 'dewPoint' => $result->dewPoint]);
    }

    private function num(Request $request, string $key): ?float
    {
        $value = $request->query->get($key);

        return is_numeric($value) ? (float) $value : null;
    }
}
