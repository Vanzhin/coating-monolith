<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service;

use App\Inquiries\Application\DTO\ExtractedParameters;

/**
 * Заземление температур: сверяет СЫРЫЕ температуры модели с текстом запроса. Если число-температуру
 * модель вернула, а в тексте (тело + вложения) его нет — это вероятная галлюцинация (для защитного
 * покрытия неверная T опасна). Не отбрасываем значение (текст мог перефразировать), а предупреждаем
 * инженера. «Модель предлагает — код проверяет», как SubstanceResolver для веществ.
 */
final class TemperatureGroundingChecker
{
    /**
     * @return list<string> предупреждения о температурах, не найденных в тексте
     */
    public function check(ExtractedParameters $params, string $text): array
    {
        $warnings = [];
        foreach ($this->temperatures($params) as [$value, $label]) {
            if (!$this->appearsInText($value, $text)) {
                $warnings[] = sprintf('Температура %d °C (%s) в тексте не найдена — возможно, выдумана моделью.', $value, $label);
            }
        }

        return $warnings;
    }

    /**
     * @return list<array{int, string}> непустые температуры с подписью поля
     */
    private function temperatures(ExtractedParameters $params): array
    {
        $candidates = [
            [$params->chemicalResistance->maxTemperature, 'рабочая у вещества'],
            [$params->chemicalResistance->peakTemperature, 'пиковая у вещества'],
            [$params->thermal->maxTemperature, 'рабочая нагрева'],
            [$params->thermal->peakTemperature, 'пиковая нагрева'],
            [$params->applicationMinTemp?->from, 'нанесение, от'],
            [$params->applicationMinTemp?->to, 'нанесение, до'],
        ];

        $out = [];
        foreach ($candidates as [$value, $label]) {
            if (null !== $value) {
                $out[] = [$value, $label];
            }
        }

        return $out;
    }

    /**
     * Число встречается в тексте как отдельное — НЕ часть большего числа (1908) и НЕ часть дробного
     * (4 в «4,0 мм»): границы — цифра/десятичный разделитель. Точка/запятая в конце предложения
     * («до 90.») заземление не ломают (за разделителем нет цифры). Сверяем по абсолютной величине —
     * знак в тексте бывает разный (−10/минус 10), важны цифры.
     */
    private function appearsInText(int $value, string $text): bool
    {
        $digits = (string) abs($value);

        return 1 === preg_match('/(?<![\d.,])'.$digits.'(?!\d)(?![.,]\d)/u', $text);
    }
}
