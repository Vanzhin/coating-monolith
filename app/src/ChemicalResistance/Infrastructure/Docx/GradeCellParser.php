<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Docx;

use App\Shared\Infrastructure\Exception\AppException;

final class GradeCellParser
{
    private const GRADES = ['R', 'NR', 'LR', 'FS', 'NT'];

    public function parse(string $cell): ParsedAssessment
    {
        $cell = trim(preg_replace('/\s+/u', ' ', $cell));
        if ($cell === '') {
            throw new AppException('Пустая ячейка оценки.');
        }

        // Special case: "NT/FS" — take first (NT).
        if (preg_match('#^(NT)/FS$#i', $cell, $m)) {
            return new ParsedAssessment(strtoupper($m[1]), null, []);
        }

        // Split by comma, but keep "Прим. 1,4,6" together — pre-normalize.
        // Strategy: replace "Прим. 1,4,6" → "Прим. 1|4|6" so comma-split doesn't cut inside.
        // Loop until stable to handle multi-way joins (1,4,6 → 1|4|6).
        $work = $cell;
        do {
            $prev = $work;
            $work = preg_replace_callback(
                '/(Прим\.\s*[\d|]+),(\d+)/u',
                function ($m) {
                    return $m[1] . '|' . $m[2];
                },
                $work
            );
        } while ($work !== $prev);

        $parts = array_map('trim', explode(',', $work));

        $grade = null;
        $maxT = null;
        $noteLabels = [];

        foreach ($parts as $p) {
            if ($p === '') continue;

            // Grade?
            if (in_array(strtoupper($p), self::GRADES, true)) {
                $grade ??= strtoupper($p);
                continue;
            }
            // Temperature: "60ºC", "60°C", "60ºc", "60 °C", etc.
            if (preg_match('/^(\d+)\s*[°º]?[CСcс]$/u', $p, $m)) {
                $maxT = (int)$m[1];
                continue;
            }
            // Note ref, single or joined by | (from earlier collapse).
            if (preg_match('/^Прим\.\s*(\d+(?:\|\d+)*)$/u', $p, $m)) {
                foreach (explode('|', $m[1]) as $n) {
                    $label = 'Прим. ' . $n;
                    if (!in_array($label, $noteLabels, true)) $noteLabels[] = $label;
                }
                continue;
            }
            // Unknown token — silently skip (docx has occasional junk like "*Shell").
        }

        if ($grade === null) {
            throw new AppException(sprintf('Не удалось распознать оценку в ячейке «%s».', $cell));
        }
        return new ParsedAssessment($grade, $maxT, $noteLabels);
    }
}
