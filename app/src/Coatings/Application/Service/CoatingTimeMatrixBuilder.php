<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;

/**
 * Собирает matrix-таблицу времени высыхания для preview-модалки покрытия
 * (визуально соответствует «Время высыхания» в тех-паспорте: колонки —
 * температуры, строки — названные серии, ячейки — длительности).
 *
 * Колонки: температурная сетка [application_min_temp .. drying_max_temp]
 * с шагом STEP; крайний max добавляется отдельно если шаг с ним не бьётся.
 *
 * Строки: сухой на отлип, полное отверждение, интервал перекрытия
 * (мин/макс) — по каждому root + env-ветки (атмосферная / погружение /
 * спец. среды) если у ветки есть свои точки. Пустые серии в строки не
 * попадают.
 *
 * Значения ячейки: точное совпадение с точкой → её time_in_minutes; между
 * двумя точками — линейная интерполяция (флаг is_calculated); вне диапазона
 * определённых точек, а также при «unlimited/N-A» в bounding'ах — null
 * (шаблон рендерит «—»).
 */
final class CoatingTimeMatrixBuilder
{
    private const STEP = 10;

    /**
     * Стандартная лабораторная температура — 23 °C. Всегда попадает в матрицу,
     * если укладывается в [applicationMinTemp, dryingMaxTemp], даже когда шаг
     * с ней не бьётся. Мотивация — в тех-паспортах (например, Литамастик 190 Ст)
     * жизнеспособность и опорные точки всегда фиксируются при 23 °C.
     */
    private const REFERENCE_TEMP = 23;

    private const ENV_LABELS = [
        'atmospheric' => 'атмосферная эксплуатация',
        'immersion'   => 'эксплуатация при погружении',
        'special'     => 'спец. среды',
    ];

    /**
     * @return array{
     *   columns: list<int>,
     *   rows: list<array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}>
     * }
     */
    public function build(CoatingDTO $coating): array
    {
        $columns = $this->computeColumns($coating->applicationMinTemp, $coating->dryingMaxTemp);
        $rows = [];

        if ($coating->dryToTouch !== []) {
            $rows[] = $this->rowFromSeries('Сухой на отлип', $coating->dryToTouch, $columns);
        }
        if ($coating->fullCure !== []) {
            $rows[] = $this->rowFromSeries('Полное отверждение', $coating->fullCure, $columns);
        }

        $this->addRecoatingRows($rows, 'Интервал перекрытия (мин)', $coating->minRecoatingInterval, $columns);
        if ($coating->maxRecoatingInterval !== null) {
            $this->addRecoatingRows($rows, 'Интервал перекрытия (макс.)', $coating->maxRecoatingInterval, $columns);
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @return list<int> */
    private function computeColumns(int $min, int $max): array
    {
        $columns = [];
        for ($t = $min; $t <= $max; $t += self::STEP) {
            $columns[] = $t;
        }
        if ($columns === [] || end($columns) !== $max) {
            $columns[] = $max;
        }
        if (self::REFERENCE_TEMP >= $min && self::REFERENCE_TEMP <= $max
            && !in_array(self::REFERENCE_TEMP, $columns, true)) {
            $columns[] = self::REFERENCE_TEMP;
        }
        sort($columns);
        return array_values(array_unique($columns));
    }

    /**
     * @param list<array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}> $rows
     * @param list<int> $columns
     */
    private function addRecoatingRows(array &$rows, string $baseLabel, RecoatingIntervalTreeDTO $tree, array $columns): void
    {
        if ($tree->default !== []) {
            $rows[] = $this->rowFromSeries($baseLabel, $tree->default, $columns);
        }
        foreach (self::ENV_LABELS as $key => $envLabel) {
            $branch = $tree->branches[$key] ?? null;
            if ($branch !== null && $branch->default !== []) {
                $rows[] = $this->rowFromSeries("{$baseLabel}, {$envLabel}", $branch->default, $columns);
            }
        }
    }

    /**
     * @param list<DryingTimePointDTO> $points
     * @param list<int> $columns
     * @return array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}
     */
    private function rowFromSeries(string $label, array $points, array $columns): array
    {
        $values = [];
        foreach ($columns as $t) {
            $values[$t] = $this->resolve($points, $t);
        }
        return ['label' => $label, 'values' => $values];
    }

    /**
     * @param list<DryingTimePointDTO> $points
     * @return array{minutes: ?int, is_calculated: bool}
     */
    private function resolve(array $points, int $t): array
    {
        if ($points === []) {
            return ['minutes' => null, 'is_calculated' => false];
        }

        usort($points, static fn(DryingTimePointDTO $a, DryingTimePointDTO $b) => $a->temperature_at <=> $b->temperature_at);

        // Точное совпадение → отдаём исходное значение вместе с исходным is_calculated.
        foreach ($points as $p) {
            if ($p->temperature_at === $t) {
                return ['minutes' => $p->time_in_minutes, 'is_calculated' => $p->is_calculated];
            }
        }

        // Ищем bounding точки.
        $lower = null;
        $upper = null;
        foreach ($points as $p) {
            if ($p->temperature_at < $t) {
                $lower = $p;
            } elseif ($p->temperature_at > $t && $upper === null) {
                $upper = $p;
                break;
            }
        }

        // Вне диапазона (только один bound или ничего).
        if ($lower === null || $upper === null) {
            return ['minutes' => null, 'is_calculated' => false];
        }

        // Один из bound'ов null/unlimited — интерполяция физически не имеет смысла.
        if ($lower->time_in_minutes === null || $upper->time_in_minutes === null
            || $lower->time_in_minutes === 0 || $upper->time_in_minutes === 0) {
            return ['minutes' => null, 'is_calculated' => false];
        }

        $t0 = $lower->temperature_at;
        $t1 = $upper->temperature_at;
        $v0 = $lower->time_in_minutes;
        $v1 = $upper->time_in_minutes;
        $interpolated = (int) round($v0 + ($v1 - $v0) * ($t - $t0) / ($t1 - $t0));

        return ['minutes' => $interpolated, 'is_calculated' => true];
    }
}
