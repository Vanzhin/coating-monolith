<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Расчёт промежуточной точки для температурно-зависимой длительности.
 * Используется кнопкой «Рассчитать» в модалке редактирования формы покрытия —
 * чтобы единая логика интерполяции жила только в доменном `Series`,
 * без дублирования формулы на фронте.
 *
 * Запрос:
 *   POST /cabinet/coating/series/interpolate
 *   { "targetTemperature": 23,
 *     "points": [
 *       { "temperature_at": 5,  "minutes": 1440 },
 *       { "temperature_at": 20, "minutes": 240  }
 *     ]
 *   }
 *
 * Ответ:
 *   200 { "minutes": 880 }
 *   400 { "error": "<человекочитаемое сообщение>" }
 */
#[Route(
    path: '/cabinet/coating/series/interpolate',
    name: 'app_cabinet_coating_series_interpolate',
    methods: ['POST'],
)]
class InterpolateAction extends AbstractController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $targetTemp = (int) ($data['targetTemperature'] ?? 0);
        $rawPoints = is_array($data['points'] ?? null) ? $data['points'] : [];

        if (count($rawPoints) < 2) {
            return $this->errorResponse('Нужно как минимум две заданные точки для расчёта.');
        }

        try {
            $points = array_map(
                fn (array $p) => new TimeAtTemperature(
                    (int) ($p['temperature_at'] ?? 0),
                    (int) ($p['minutes'] ?? 0),
                ),
                $rawPoints,
            );
            $series = new DryingTimeSeries(...$points);
            $result = $series->getPoint($targetTemp);
        } catch (AppException $e) {
            return $this->errorResponse($e->getMessage());
        }

        if (null === $result) {
            return $this->errorResponse(sprintf(
                'Температура +%d °C находится вне диапазона заданных точек — расчёт невозможен.',
                $targetTemp,
            ));
        }

        return new JsonResponse(['minutes' => $result->timeInMinutes]);
    }

    private function errorResponse(string $message): JsonResponse
    {
        // Глобальный ResponseListener оборачивает JSON в {result,status,data,message}
        // и берёт текст из ключа `message`, поэтому отдаём именно его.
        return new JsonResponse(['message' => $message], 400);
    }
}
