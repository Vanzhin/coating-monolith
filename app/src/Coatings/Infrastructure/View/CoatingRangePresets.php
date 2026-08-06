<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\View;

/**
 * UI-пресеты диапазонов для формы фильтра списка покрытий. Чисто визуальные
 * shortcut'ы: чип ставит слайдер в bounds и submit'ит форму с явными from/to.
 * Backend никакого «preset key» не знает — принимает голые from/to.
 */
final class CoatingRangePresets
{
    /** @return array<string, array{label: string, from: int, to: int}> */
    public static function appMinTemp(): array
    {
        return [
            'winter' => ['label' => 'Зимнее (ниже -5)', 'from' => -30, 'to' => -5],
            'standard' => ['label' => 'Стандартное (-5..+5)', 'from' => -5, 'to' => 5],
            'summer' => ['label' => 'Летнее (более +5)', 'from' => 5, 'to' => 50],
        ];
    }

    /** @return array<string, array{label: string, from: int, to: int}> */
    public static function volumeSolid(): array
    {
        return [
            'low' => ['label' => 'Низкий (≤ 40 %)', 'from' => 10, 'to' => 40],
            'medium' => ['label' => 'Средний (40–70 %)', 'from' => 40, 'to' => 70],
            'high' => ['label' => 'Высокий (≥ 70 %)', 'from' => 70, 'to' => 100],
        ];
    }

    /** Мин интервал перекрытия при +20 °C, в ЧАСАХ (верх 168 = 1 неделя).
     * @return array<string, array{label: string, from: int, to: int}> */
    public static function minRecoat20(): array
    {
        return [
            'fast' => ['label' => 'Быстрый (≤ 4 ч)', 'from' => 0, 'to' => 4],
            'standard' => ['label' => 'Стандарт (4–24 ч)', 'from' => 4, 'to' => 24],
            'slow' => ['label' => 'Медленный (1–3 сут)', 'from' => 24, 'to' => 72],
            'very_slow' => ['label' => 'Долгий (> 3 сут)', 'from' => 72, 'to' => 168],
        ];
    }

    /** Макс интервал перекрытия при +20 °C, в ДНЯХ (верх 365 = 1 год).
     * @return array<string, array{label: string, from: int, to: int}> */
    public static function maxRecoat20(): array
    {
        return [
            'day' => ['label' => '≤ 1 сут', 'from' => 0, 'to' => 1],
            'week' => ['label' => '1–7 сут', 'from' => 1, 'to' => 7],
            'month' => ['label' => '1–4 нед', 'from' => 7, 'to' => 28],
            'long' => ['label' => '> 4 нед', 'from' => 28, 'to' => 365],
        ];
    }
}
