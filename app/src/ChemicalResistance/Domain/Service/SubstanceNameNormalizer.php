<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

/**
 * Единственный источник правды для сравнения названий веществ как дубликатов.
 * Используется:
 *  - Substance::canonicalNameKey (UNIQUE в БД);
 *  - Substance::hasName / addAlias (проверка внутри агрегата);
 *  - SubstanceLookup при импорте (findOrCreateByName).
 */
final class SubstanceNameNormalizer
{
    public static function normalize(string $raw): string
    {
        $s = \Normalizer::normalize($raw, \Normalizer::FORM_KC) ?: $raw;
        $s = mb_strtolower($s, 'UTF-8');
        // языковые маркеры (N)/(G)/(n)/(g) — норвежское/немецкое написание
        $s = preg_replace('/\([ng]\)/u', '', $s) ?? $s;
        // торговые пометки: *Shell, *TRADENAME Exxon, *™ Famm — всё что окружено *
        // удаляем всё от * до конца строки (включая всё, что может быть после *)
        $s = preg_replace('/\*.*/u', '', $s) ?? $s;
        // технические разделители — пробелы/тире/точки/запятые/слэши/скобки
        $s = preg_replace('/[\s\-.,;\/\\\\()]+/u', '', $s) ?? $s;
        return trim($s);
    }
}
