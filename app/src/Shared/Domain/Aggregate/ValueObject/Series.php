<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use JsonSerializable;

abstract readonly class Series implements JsonSerializable
{
    /**
     * @var array<int|string, SeriesPoint> Ассоциативный массив [key => SeriesPoint],
     * отсортирован по возрастанию ключа. Уникальность ключей обеспечена самой структурой массива.
     */
    public array $points;

    public function __construct(SeriesPoint ...$points)
    {
        $this->assertNotEmpty($points);

        $byKey = $this->indexByKey($points);
        ksort($byKey);

        $this->points = $byKey;
        $this->validate();
    }

    abstract protected function validate(): void;

    abstract protected function createPoint(int|float $key, int|float $value, bool $isCalculated): SeriesPoint;

    /**
     * Возвращает новую Series с добавленной или перезаписанной по ключу точкой.
     */
    public function withPoint(SeriesPoint $point): static
    {
        $newPoints = $this->points;
        $newPoints[$point->getKey()] = $point;

        return new static(...array_values($newPoints));
    }

    public function getPoint(int|float $key): ?SeriesPoint
    {
        if (isset($this->points[$key])) {
            return $this->points[$key];
        }

        [$lower, $upper] = $this->findBoundingPoints($key);
        if ($lower === null || $upper === null) {
            return null;
        }

        $value = $this->linearInterpolate($key, $lower, $upper);

        return $this->createPoint($key, $value, isCalculated: true);
    }

    /**
     * @return array<int|string, ?SeriesPoint>
     */
    public function getRange(int|float $from, int|float $to, int|float $step): array
    {
        if ($step <= 0) {
            throw new AppException('Шаг должен быть положительным.');
        }
        if ($from > $to) {
            throw new AppException('Параметр "from" должен быть меньше или равен "to".');
        }

        $result = [];
        $totalSteps = (int) floor(($to - $from) / $step);
        for ($i = 0; $i <= $totalSteps; $i++) {
            $key = $from + ($i * $step);
            $arrayKey = is_float($key) ? (string) $key : $key;
            $result[$arrayKey] = $this->getPoint($key);
        }

        return $result;
    }

    public function map(callable $fn): static
    {
        $newPoints = [];
        foreach ($this->points as $point) {
            $newValue = $fn($point->getValue(), $point->getKey());
            $newPoints[] = $this->createPoint($point->getKey(), $newValue, $point->isCalculated());
        }

        return new static(...$newPoints);
    }

    public function multiply(float $factor): static
    {
        return $this->map(fn(int|float $value) => $value * $factor);
    }

    /**
     * @return list<mixed>
     */
    public function jsonSerialize(): array
    {
        return array_map(fn(SeriesPoint $p) => $p->jsonSerialize(), array_values($this->points));
    }

    /**
     * @param list<SeriesPoint> $points
     */
    private function assertNotEmpty(array $points): void
    {
        if (count($points) === 0) {
            throw new AppException('Series должна содержать минимум одну точку.');
        }
    }

    /**
     * @param list<SeriesPoint> $points
     * @return array<int|string, SeriesPoint>
     */
    private function indexByKey(array $points): array
    {
        $byKey = [];
        foreach ($points as $point) {
            $byKey[$point->getKey()] = $point;
        }
        return $byKey;
    }

    /**
     * @return array{0: ?SeriesPoint, 1: ?SeriesPoint}
     */
    private function findBoundingPoints(int|float $key): array
    {
        $lower = null;
        $upper = null;
        foreach ($this->points as $point) {
            if ($point->getKey() <= $key) {
                $lower = $point;
            }
            if ($point->getKey() >= $key) {
                $upper = $point;
                break;
            }
        }
        return [$lower, $upper];
    }

    private function linearInterpolate(int|float $key, SeriesPoint $lower, SeriesPoint $upper): int|float
    {
        $lowerKey = $lower->getKey();
        $upperKey = $upper->getKey();
        if ($upperKey === $lowerKey) {
            return $lower->getValue();
        }

        $lowerValue = $lower->getValue();
        $upperValue = $upper->getValue();

        return $lowerValue + ($upperValue - $lowerValue) * ($key - $lowerKey) / ($upperKey - $lowerKey);
    }
}
