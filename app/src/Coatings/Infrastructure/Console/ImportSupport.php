<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

/**
 * Чистые хелперы импорта систем покрытий из JSON. Инфраструктура: сопоставление
 * «сырых» строк из docx с данными БД, без доменной логики.
 */
final class ImportSupport
{
    /**
     * Нормализует название покрытия для матчинга источник ↔ БД: регистр, лишние
     * пробелы (в т.ч. неразрывные), ё→е и латинская c→кириллическая с (в источнике
     * встречается «Литатанк АС» латиницей против «ЛИТАТАНК АC» в БД).
     */
    public static function normalizeTitle(string $s): string
    {
        $s = str_replace("\u{00A0}", ' ', $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);
        $s = mb_strtolower(trim($s));
        $s = str_replace('ё', 'е', $s);

        return str_replace('c', 'с', $s);
    }

    /**
     * Максимум толщины из ячейки ТСП: «60-80» → 80, «60» → 60, «По ПТМ» → null
     * (нечисловая толщина, напр. расчётная огнезащита — в доменную модель не ложится).
     */
    public static function maxDft(string $s): ?int
    {
        preg_match_all('/\d+/', $s, $m);
        if ([] === $m[0]) {
            return null;
        }

        return max(array_map('intval', $m[0]));
    }
}
