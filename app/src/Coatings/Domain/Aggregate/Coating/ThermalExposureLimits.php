<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;
use JsonSerializable;

/**
 * Границы температурного воздействия на покрытие (уже нанесённое и высохшее),
 * отдельно для сухого тепла и для погружения. Значения — по данным тех-паспорта
 * производителя, никакой физики домен не считает.
 *
 * Модель отражает то, как это пишут в реальных PDS'ах Литум/Hempel/…:
 *   -30..+100 °C непрерывно, до +120 °C кратковременно (пиковое, не более 60 мин).
 *
 * Все 4 поля независимо-опциональные — тех-паспорт может не документировать
 * какую-то границу, но остальные сохраняются. VO существует, если задано хотя
 * бы одно поле; когда все поля пустые, вызывающий должен передать null вместо
 * инстанса (см. CoatingMapper::buildExposureFromInput).
 *
 * Парные инварианты (проверяются ТОЛЬКО когда обе стороны заданы):
 *   1) continuousMin < continuousMax.
 *   2) peakMax >= continuousMax — пик не может быть ниже непрерывного максимума
 *      (равенство допустимо: некоторые PDS'ы дают одно и то же значение).
 *
 * Одиночные инварианты:
 *   3) peakDurationMinutes без peakMax — бессмысленно (длительность чего?).
 *   4) peakDurationMinutes > 0.
 *
 * Хранение — JSONB, через ThermalExposureLimitsType (Doctrine DBAL). Nullable
 * на уровне coating: null = никаких границ вообще не задокументировано.
 */
final readonly class ThermalExposureLimits implements JsonSerializable
{
    /** Дефолт длительности пикового воздействия, когда peakMax указан, а duration — нет. */
    public const DEFAULT_PEAK_DURATION_MINUTES = 60;

    /** Разумные границы температуры — отсечь мусор ввода. */
    private const MIN_TEMPERATURE = -200;
    private const MAX_TEMPERATURE = 1000;

    public ?int $continuousMin;
    public ?int $continuousMax;
    public ?int $peakMax;
    public ?int $peakDurationMinutes;

    public function __construct(
        ?int $continuousMin = null,
        ?int $continuousMax = null,
        ?int $peakMax = null,
        ?int $peakDurationMinutes = null,
    ) {
        // VO без данных не создаём — вызывающий должен передать null. Это защита
        // от «пустых» записей в БД, отличных от отсутствия записи.
        if ($continuousMin === null && $continuousMax === null && $peakMax === null && $peakDurationMinutes === null) {
            throw new AppException('Пределы эксплуатации: нужно указать хотя бы одно значение.');
        }

        self::assertTemperatureInRange('минимальная непрерывная', $continuousMin);
        self::assertTemperatureInRange('максимальная непрерывная', $continuousMax);
        self::assertTemperatureInRange('пиковая', $peakMax);

        // Если peak указан без явной длительности — по умолчанию 60 мин (стандарт
        // тех-паспортов Литум/Hempel).
        if ($peakMax !== null && $peakDurationMinutes === null) {
            $peakDurationMinutes = self::DEFAULT_PEAK_DURATION_MINUTES;
        }

        if ($continuousMin !== null && $continuousMax !== null && $continuousMin >= $continuousMax) {
            throw new AppException(sprintf(
                'Минимальная температура непрерывной эксплуатации (%+d °C) должна быть строго меньше максимальной (%+d °C).',
                $continuousMin,
                $continuousMax,
            ));
        }
        if ($peakMax !== null && $continuousMax !== null && $peakMax < $continuousMax) {
            throw new AppException(sprintf(
                'Пиковая температура (%+d °C) должна быть не ниже максимальной непрерывной (%+d °C).',
                $peakMax,
                $continuousMax,
            ));
        }
        if ($peakDurationMinutes !== null && $peakMax === null) {
            throw new AppException(
                'Длительность пикового воздействия задана без самой пиковой температуры — уточните пиковую температуру.'
            );
        }
        if ($peakDurationMinutes !== null && $peakDurationMinutes <= 0) {
            throw new AppException('Длительность пикового воздействия должна быть положительной.');
        }

        $this->continuousMin = $continuousMin;
        $this->continuousMax = $continuousMax;
        $this->peakMax = $peakMax;
        $this->peakDurationMinutes = $peakDurationMinutes;
    }

    /**
     * public static — чтобы CoatingsFilter мог валидировать вход температурного
     * фасета той же самой проверкой без вытаскивания констант наружу.
     * Один владелец правила «температура в разумных границах» — этот VO.
     */
    public static function assertTemperatureInRange(string $label, ?int $temperature): void
    {
        if ($temperature === null) {
            return;
        }
        if ($temperature < self::MIN_TEMPERATURE || $temperature > self::MAX_TEMPERATURE) {
            throw new AppException(sprintf(
                'Температура (%s) %+d °C выходит за допустимые границы %+d…%+d °C.',
                $label,
                $temperature,
                self::MIN_TEMPERATURE,
                self::MAX_TEMPERATURE,
            ));
        }
    }

    /** @param array{continuous_min?:?int, continuous_max?:?int, peak_max?:?int, peak_duration_minutes?:?int} $row */
    public static function fromArray(array $row): self
    {
        return new self(
            $row['continuous_min'] ?? null,
            $row['continuous_max'] ?? null,
            $row['peak_max'] ?? null,
            $row['peak_duration_minutes'] ?? null,
        );
    }

    public function jsonSerialize(): array
    {
        return [
            'continuous_min' => $this->continuousMin,
            'continuous_max' => $this->continuousMax,
            'peak_max' => $this->peakMax,
            'peak_duration_minutes' => $this->peakDurationMinutes,
        ];
    }

    /**
     * Покрывает ли этот диапазон температуру $temperature.
     * $includingPeak=true — верхняя граница расширяется до peakMax (если задан).
     * null-граница = «не задокументировано» = без ограничения в эту сторону
     * (иначе покрытия с частично заполненными границами были бы бесполезны
     * в поиске: пользователь ищет «держит +90 °C», в PDS написан только +120 °C
     * сверху — покрытие должно попадать в выборку).
     */
    public function covers(int $temperature, bool $includingPeak): bool
    {
        if ($this->continuousMin !== null && $temperature < $this->continuousMin) {
            return false;
        }
        $upper = $includingPeak && $this->peakMax !== null
            ? $this->peakMax
            : $this->continuousMax;
        return $upper === null || $temperature <= $upper;
    }
}
