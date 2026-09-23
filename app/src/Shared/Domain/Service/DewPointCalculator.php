<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Физика точки росы и правило конденсации при нанесении (ISO 8502-4): наносить можно, только если
 * температура поверхности выше точки росы на запас (обычно +3 °C) — иначе на поверхности конденсат
 * и адгезия под угрозой. Чистая физика, не привязана к контексту (используют Reports и раздел
 * «Инструменты») → живёт в Shared. Фронт дублирует формулу лишь ради офлайна.
 *
 * Точка росы из температуры воздуха и относительной влажности — формула Магнуса
 * (коэффициенты Alduchov–Eskridge, 1996; погрешность < 0.4 °C в диапазоне 0..60 °C):
 *   γ  = ln(RH/100) + a·T / (b + T)
 *   Td = b·γ / (a − γ),   a = 17.625, b = 243.04 °C
 */
final class DewPointCalculator
{
    /** Запас температуры поверхности над точкой росы по умолчанию, °C (ISO 8502-4). */
    public const DEFAULT_MARGIN_C = 3.0;

    private const MAGNUS_A = 17.625;
    private const MAGNUS_B = 243.04;

    /** Минимально допустимая температура поверхности для нанесения (°C): точка росы плюс запас. */
    public function minSurfaceTemperature(float $dewPoint, float $margin = self::DEFAULT_MARGIN_C): float
    {
        return $dewPoint + $margin;
    }

    /** Можно ли наносить: температура поверхности не ниже «точка росы + запас». */
    public function isSurfaceAcceptable(
        float $surfaceTemperature,
        float $dewPoint,
        float $margin = self::DEFAULT_MARGIN_C,
    ): bool {
        return $surfaceTemperature >= $this->minSurfaceTemperature($dewPoint, $margin);
    }

    /** Точка росы (°C) из температуры воздуха (°C) и относительной влажности (формула Магнуса). */
    public function dewPoint(float $airTemperature, Percent $humidity): float
    {
        $relativeHumidity = $humidity->value();
        if ($relativeHumidity <= 0) {
            throw new AppException('Относительная влажность должна быть больше 0 % для расчёта точки росы.');
        }

        $gamma = log($relativeHumidity / 100) + self::MAGNUS_A * $airTemperature / (self::MAGNUS_B + $airTemperature);

        return self::MAGNUS_B * $gamma / (self::MAGNUS_A - $gamma);
    }
}
