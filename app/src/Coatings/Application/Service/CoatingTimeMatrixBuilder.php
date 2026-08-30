<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\Coatings\DryingTimePointDTO;
use App\Coatings\Application\DTO\Coatings\RecoatingIntervalTreeDTO;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;

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
     * Обязательные точки колонок, если укладываются в [applicationMinTemp,
     * dryingMaxTemp]. Даже когда шаг step-10 с ними не бьётся — 0°C и 20°C
     * добавляются: 0 — граница мороза, 20 — типовая комнатная. Все
     * пользователи ждут увидеть эти отметки в тех-паспорте.
     */
    private const MANDATORY_TEMPS = [0, 20];

    private const ENV_LABELS = [
        'atmospheric' => 'Атмосфера',
        'immersion' => 'Погружение',
        'special' => 'Спец. среды',
    ];

    private const RECOAT_MIN = 'Интервал перекрытия (мин)';
    private const RECOAT_MAX = 'Интервал перекрытия (макс.)';

    /**
     * Строки сгруппированы по контексту: базовая группа (label=null) — сухой на
     * отлип / полное отверждение / интервал перекрытия (мин/макс) без ветвления;
     * затем группы по средам и средам→материалам (label=подзаголовок) — свои
     * мин/макс собраны вместе. Шаблон рисует подзаголовок на непустой label.
     *
     * @return array{
     *   columns: list<int>,
     *   groups: list<array{
     *     label: ?string,
     *     rows: list<array{stage: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}>
     *   }>
     * }
     */
    public function build(CoatingDTO $coating): array
    {
        $columns = $this->computeColumns($coating->applicationMinTemp, $coating->dryingMaxTemp);
        $groups = [];

        // Базовая группа (без подзаголовка).
        $baseRows = [];
        if ([] !== $coating->dryToTouch) {
            $baseRows[] = $this->rowFromSeries('Сухой на отлип', $coating->dryToTouch, $columns);
        }
        if ([] !== $coating->fullCure) {
            $baseRows[] = $this->rowFromSeries('Полное отверждение', $coating->fullCure, $columns);
        }
        if ([] !== $coating->minRecoatingInterval->default) {
            $baseRows[] = $this->rowFromSeries(self::RECOAT_MIN, $coating->minRecoatingInterval->default, $columns);
        }
        if (null !== $coating->maxRecoatingInterval && [] !== $coating->maxRecoatingInterval->default) {
            $baseRows[] = $this->rowFromSeries(self::RECOAT_MAX, $coating->maxRecoatingInterval->default, $columns);
        }
        if ([] !== $baseRows) {
            $groups[] = ['label' => null, 'rows' => $baseRows];
        }

        // Группы по средам и средам→материалам: мин и макс одного контекста рядом.
        $this->addContextGroups($groups, $coating->minRecoatingInterval, $coating->maxRecoatingInterval, $columns);

        return ['columns' => $columns, 'groups' => $groups];
    }

    /**
     * @param list<array{label: ?string, rows: list<array{stage: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}>}> $groups
     * @param list<int>                                                                                                                    $columns
     */
    private function addContextGroups(array &$groups, RecoatingIntervalTreeDTO $minTree, ?RecoatingIntervalTreeDTO $maxTree, array $columns): void
    {
        foreach (self::ENV_LABELS as $envKey => $envLabel) {
            $minEnv = $minTree->branches[$envKey] ?? null;
            $maxEnv = $maxTree?->branches[$envKey] ?? null;

            // Ветка среды (env default): мин, затем макс.
            $envRows = [];
            if (null !== $minEnv && [] !== $minEnv->default) {
                $envRows[] = $this->rowFromSeries(self::RECOAT_MIN, $minEnv->default, $columns);
            }
            if (null !== $maxEnv && [] !== $maxEnv->default) {
                $envRows[] = $this->rowFromSeries(self::RECOAT_MAX, $maxEnv->default, $columns);
            }
            if ([] !== $envRows) {
                $groups[] = ['label' => $envLabel, 'rows' => $envRows];
            }

            // Материалы под средой (env → base): ключи из min- и max-веток.
            $baseKeys = array_keys(($minEnv->branches ?? []) + ($maxEnv->branches ?? []));
            foreach ($baseKeys as $baseKey) {
                $minBase = $minEnv->branches[$baseKey] ?? null;
                $maxBase = $maxEnv->branches[$baseKey] ?? null;

                $matRows = [];
                if (null !== $minBase && [] !== $minBase->default) {
                    $matRows[] = $this->rowFromSeries(self::RECOAT_MIN, $minBase->default, $columns);
                }
                if (null !== $maxBase && [] !== $maxBase->default) {
                    $matRows[] = $this->rowFromSeries(self::RECOAT_MAX, $maxBase->default, $columns);
                }
                if ([] !== $matRows) {
                    $groups[] = ['label' => $envLabel.' · '.$this->baseTitle((string) $baseKey), 'rows' => $matRows];
                }
            }
        }
    }

    /** @return list<int> */
    private function computeColumns(int $min, int $max): array
    {
        $columns = [];
        for ($t = $min; $t <= $max; $t += self::STEP) {
            $columns[] = $t;
        }
        if ([] === $columns || end($columns) !== $max) {
            $columns[] = $max;
        }
        foreach (self::MANDATORY_TEMPS as $t) {
            if ($t >= $min && $t <= $max) {
                $columns[] = $t;
            }
        }
        sort($columns);

        return array_values(array_unique($columns));
    }

    /** Русское название базы ЛКМ по её нормализованному (lower-case) ключу дерева. */
    private function baseTitle(string $baseKey): string
    {
        $enum = CoatingBase::tryFrom(strtoupper($baseKey));

        return $enum?->title() ?? strtoupper($baseKey);
    }

    /**
     * @param list<DryingTimePointDTO> $points
     * @param list<int>                $columns
     *
     * @return array{stage: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}
     */
    private function rowFromSeries(string $stage, array $points, array $columns): array
    {
        $values = [];
        foreach ($columns as $t) {
            $values[$t] = $this->resolve($points, $t);
        }

        return ['stage' => $stage, 'values' => $values];
    }

    /**
     * @param list<DryingTimePointDTO> $points
     *
     * @return array{minutes: ?int, is_calculated: bool}
     */
    private function resolve(array $points, int $t): array
    {
        if ([] === $points) {
            return ['minutes' => null, 'is_calculated' => false];
        }

        usort($points, static fn (DryingTimePointDTO $a, DryingTimePointDTO $b) => $a->temperature_at <=> $b->temperature_at);

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
            } elseif ($p->temperature_at > $t && null === $upper) {
                $upper = $p;
                break;
            }
        }

        // Вне диапазона (только один bound или ничего).
        if (null === $lower || null === $upper) {
            return ['minutes' => null, 'is_calculated' => false];
        }

        // Один из bound'ов null/unlimited — интерполяция физически не имеет смысла.
        if (null === $lower->time_in_minutes || null === $upper->time_in_minutes
            || 0 === $lower->time_in_minutes || 0 === $upper->time_in_minutes) {
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
