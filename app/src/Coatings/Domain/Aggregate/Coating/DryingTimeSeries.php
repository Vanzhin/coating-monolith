<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use Carbon\CarbonInterval;

final readonly class DryingTimeSeries implements TimeSeries
{
    /** @var list<TimeAtTemperature> Отсортирован по возрастанию температуры */
    public array $points;

    /**
     * @throws AppException
     */
    public function __construct(TimeAtTemperature ...$points)
    {
        $this->assertNotEmpty($points);

        $sortedPoints = $this->sortPoints($points);

        // Один проход для проверки дубликатов и физического правила
        $this->validatePointsConsistency($sortedPoints);

        $this->points = $sortedPoints;
    }

    /**
     * Точка при заданной температуре. Если температура точно совпадает с одной
     * из заданных точек — вернётся она; если находится между двумя — вернётся
     * интерполированная точка с `isCalculated=true`. Если вне диапазона — null.
     */
    public function getPoint(int $temperatureAt): ?TimeAtTemperature
    {
        // Безопасное сравнение float/int с учетом неточностей плавающей точки
        foreach ($this->points as $point) {
            if ($point->temperatureAt === $temperatureAt) {
                return $point;
            }
        }

        [$lower, $upper] = $this->findBoundingPoints($temperatureAt);
        if ($lower === null || $upper === null) {
            return null;
        }

        $interpolated = $this->linearInterpolate($temperatureAt, $lower, $upper);

        return new TimeAtTemperature(
            (int)round($temperatureAt),
            (int)round($interpolated),
            isCalculated: true,
        );
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return $this->points;
    }

    /**
     * Обратная сборка из плоского массива точек (формат jsonSerialize()).
     *
     * @param list<array<string, mixed>> $rows
     * @throws AppException
     */
    public static function fromArray(array $rows): self
    {
        $points = array_map(
            fn(array $row): TimeAtTemperature => new TimeAtTemperature(
                (int)$row['temperature_at'],
                is_numeric($row['time_in_minutes']) ? (int)$row['time_in_minutes'] : null,
                (bool)($row['is_calculated'] ?? false),
            ),
            $rows,
        );

        return new self(...$points);
    }

    private static function asNullableInt(mixed $v): ?int
    {
        return $v === null ? null : (int)$v;
    }

    /**
     * @param TimeAtTemperature[] $points
     * @throws AppException
     */
    private function assertNotEmpty(array $points): void
    {
        if (empty($points)) {
            throw new AppException('Серия времени высыхания должна содержать хотя бы одну точку.');
        }
    }

    /**
     * @param TimeAtTemperature[] $points
     * @return list<TimeAtTemperature>
     */
    private function sortPoints(array $points): array
    {
        usort($points, fn(TimeAtTemperature $a, TimeAtTemperature $b) => $a->temperatureAt <=> $b->temperatureAt);
        return array_values($points);
    }

    /**
     * Валидация всей серии за один проход (O(N))
     * @param TimeAtTemperature[] $points
     * @throws AppException
     */
    private function validatePointsConsistency(array $points): void
    {
        /** @var TimeAtTemperature|null $previous */
        $previous = null;
        /** @var TimeAtTemperature|null $previousDuration */
        $previousDuration = null;

        foreach ($points as $point) {
            if ($previous !== null) {
                // 1. Дубликат температуры запрещён для любых kind'ов.
                if ($point->temperatureAt === $previous->temperatureAt) {
                    throw new AppException(
                        sprintf(
                            'Дублирующаяся температурная точка %d °C.',
                            $point->temperatureAt,
                        )
                    );
                }
            }

            // 2. Физ-правило применяем ТОЛЬКО среди Duration-точек.
            // Unlimited (0) и Unknown (null) — пропускаем при сравнении.
            if ($point->timeInMinutes !== null && $point->timeInMinutes > 0) {
                if ($previousDuration !== null && $point->timeInMinutes > $previousDuration->timeInMinutes) {
                    throw new AppException(
                        sprintf(
                            'При +%d °C время сушки (%s) не может быть больше, чем при +%d °C (%s).',
                            $point->temperatureAt,
                            $this->humanize($point->timeInMinutes),
                            $previousDuration->temperatureAt,
                            $this->humanize($previousDuration->timeInMinutes),
                        )
                    );
                }
                $previousDuration = $point;
            }

            $previous = $point;
        }
    }

    /**
     * @return array{0: ?TimeAtTemperature, 1: ?TimeAtTemperature}
     */
    private function findBoundingPoints(int $key): array
    {
        $lower = null;
        $upper = null;

        foreach ($this->points as $point) {
            // Для интерполяции учитываем только Duration-точки.
            // Unlimited (0) и Unknown (null) — пропускаем: между ними и Duration интерполировать нельзя.
            if ($point->timeInMinutes === null || $point->timeInMinutes === 0) {
                continue;
            }
            if ($point->temperatureAt <= $key) {
                $lower = $point;
            }
            if ($point->temperatureAt >= $key) {
                $upper = $point;
                break;
            }
        }

        return [$lower, $upper];
    }

    private function linearInterpolate(int $key, TimeAtTemperature $lower, TimeAtTemperature $upper): int
    {
        if ($upper->temperatureAt === $lower->temperatureAt) {
            return $lower->timeInMinutes;
        }
        $interpolated = $lower->timeInMinutes
            + ($upper->timeInMinutes - $lower->timeInMinutes)
            * ($key - $lower->temperatureAt)
            / ($upper->temperatureAt - $lower->temperatureAt);

        return (int)round($interpolated);
    }

    private function humanize(int $minutes): string
    {
        return CarbonInterval::minutes($minutes)->locale('ru')->cascade()->forHumans(['parts' => 2]);
    }
}
