<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;

/**
 * Собирает compare-матрицу для страницы сравнения покрытий.
 * Использует CoatingTimeMatrixBuilder для каждого subject'а, затем
 * унифицирует колонки и метки строк и делает per-cell diff.
 *
 * Формат возвращаемого массива:
 * [
 *   {
 *     label: 'Сухой на отлип',       // название интервала (row-label из single-matrix)
 *     columns: [-10, 0, 10, 23, ...], // union температур ВСЕХ subject'ов, sorted
 *     rows: [
 *       {
 *         subject: CoatingDTO,        // само покрытие — для рендера title/manufacturer
 *         values: array<temp, cell>   // cell = {minutes: ?int, is_calculated: bool}
 *       },
 *       ...
 *     ],
 *     diffColumns: array<temp, true>  // температуры, где значения различаются между subject'ами
 *   },
 *   ...
 * ]
 *
 * Секции с полностью пустыми ячейками (все subject'ы дают null) отбрасываются.
 */
final class CoatingCompareMatrixBuilder
{
    public function __construct(private readonly CoatingTimeMatrixBuilder $singleBuilder)
    {
    }

    /**
     * @param list<CoatingDTO> $subjects
     *
     * @return list<array{
     *   label: string,
     *   columns: list<int>,
     *   rows: list<array{subject: CoatingDTO, values: array<int, array{minutes: ?int, is_calculated: bool}>}>,
     *   diffColumns: array<int, true>
     * }>
     */
    public function build(array $subjects): array
    {
        if ([] === $subjects) {
            return [];
        }

        $matrices = array_map(fn (CoatingDTO $s) => $this->singleBuilder->build($s), $subjects);

        $columns = $this->unionColumns($matrices);
        $labelOrder = $this->collectLabelsInEncounterOrder($matrices);

        $sections = [];
        foreach ($labelOrder as $label) {
            $subjectRows = [];
            $allEmpty = true;

            foreach ($matrices as $i => $matrix) {
                $sourceRow = $this->findRowByLabel($matrix['rows'], $label);
                $rowValues = [];
                foreach ($columns as $t) {
                    $cell = $sourceRow['values'][$t] ?? ['minutes' => null, 'is_calculated' => false];
                    $rowValues[$t] = $cell;
                    if (null !== $cell['minutes']) {
                        $allEmpty = false;
                    }
                }
                $subjectRows[] = ['subject' => $subjects[$i], 'values' => $rowValues];
            }

            if ($allEmpty) {
                continue;
            }

            $sections[] = [
                'label' => $label,
                'columns' => $columns,
                'rows' => $subjectRows,
                'diffColumns' => $this->detectDiffColumns($subjectRows, $columns),
            ];
        }

        return $sections;
    }

    /**
     * @param list<array{columns: list<int>, rows: list<array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}>}> $matrices
     *
     * @return list<int>
     */
    private function unionColumns(array $matrices): array
    {
        $all = [];
        foreach ($matrices as $matrix) {
            foreach ($matrix['columns'] as $t) {
                $all[$t] = true;
            }
        }
        $keys = array_keys($all);
        sort($keys);

        return $keys;
    }

    /**
     * Порядок первого появления метки в матрицах subject'ов. Сохраняет логичный
     * порядок (dryToTouch → fullCure → recoating root → env → env→base).
     *
     * @param list<array{columns: list<int>, rows: list<array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}>}> $matrices
     *
     * @return list<string>
     */
    private function collectLabelsInEncounterOrder(array $matrices): array
    {
        $order = [];
        foreach ($matrices as $matrix) {
            foreach ($matrix['rows'] as $row) {
                if (!in_array($row['label'], $order, true)) {
                    $order[] = $row['label'];
                }
            }
        }

        return $order;
    }

    /**
     * @param list<array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}> $rows
     *
     * @return array{label: string, values: array<int, array{minutes: ?int, is_calculated: bool}>}|null
     */
    private function findRowByLabel(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            if ($row['label'] === $label) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Столбец «различается» если между subject'ами при этой температуре
     * есть хотя бы 2 разных значения (null считается равноправным bucket'ом).
     *
     * @param list<array{subject: CoatingDTO, values: array<int, array{minutes: ?int, is_calculated: bool}>}> $subjectRows
     * @param list<int>                                                                                       $columns
     *
     * @return array<int, true>
     */
    private function detectDiffColumns(array $subjectRows, array $columns): array
    {
        $diff = [];
        foreach ($columns as $t) {
            $buckets = [];
            foreach ($subjectRows as $row) {
                $m = $row['values'][$t]['minutes'];
                $buckets[null === $m ? '__null' : (string) $m] = true;
            }
            if (count($buckets) > 1) {
                $diff[$t] = true;
            }
        }

        return $diff;
    }
}
