<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\GetCoatingsBySubstance;

use App\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings\GetResistantCoatingsQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetResistantCoatings\GetResistantCoatingsQueryHandler;
use App\ChemicalResistance\Application\UseCase\Query\GetSubstanceRefs\GetSubstanceRefsQuery;
use App\ChemicalResistance\Application\UseCase\Query\GetSubstanceRefs\GetSubstanceRefsQueryHandler;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Grade;
use App\Coatings\Application\DTO\Coatings\CoatingDTOTransformer;
use App\Coatings\Application\DTO\Coatings\CoatingResistanceDTO;
use App\Coatings\Application\DTO\Coatings\SubstanceVerdictDTO;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Оркестрирует страницу «Химстойкость» с МУЛЬТИВЫБОРОМ веществ (логика AND):
 * покрытие проходит, только если стойко к КАЖДОМУ выбранному веществу.
 * Пилюля показывает ХУДШИЙ вердикт (самый слабый грейд + самая ограничивающая температура),
 * плюс несём разбивку по веществам (для админ-правки). Стойкие — вперёд.
 */
class GetCoatingsBySubstanceQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly CoatingRepositoryInterface $coatingRepository,
        private readonly CoatingDTOTransformer $coatingDTOTransformer,
        private readonly GetResistantCoatingsQueryHandler $resistantCoatings,
        private readonly GetSubstanceRefsQueryHandler $substanceRefs,
    ) {
    }

    public function __invoke(GetCoatingsBySubstanceQuery $query): GetCoatingsBySubstanceQueryResult
    {
        // Резолвим выбранные вещества (id → имя); это же — источник чипов и порядок разбивки.
        $refs = ($this->substanceRefs)(new GetSubstanceRefsQuery($query->substanceIds));
        if ([] === $refs) {
            return new GetCoatingsBySubstanceQueryResult([], new Pager($query->page, $query->perPage, 0), []);
        }

        // По каждому веществу — карта coatingId → оценка.
        $perSubstance = [];
        foreach ($refs as $ref) {
            $map = [];
            foreach (($this->resistantCoatings)(new GetResistantCoatingsQuery($ref->id, $query->includeAll)) as $rc) {
                $map[$rc->coatingId] = $rc;
            }
            $perSubstance[$ref->id] = $map;
        }

        // AND: оставляем покрытия, присутствующие в карте КАЖДОГО вещества.
        $coatingIds = [];
        foreach (array_keys($perSubstance[$refs[0]->id]) as $coatingId) {
            $inAll = true;
            foreach ($perSubstance as $map) {
                if (!isset($map[$coatingId])) {
                    $inAll = false;
                    break;
                }
            }
            if ($inAll) {
                $coatingIds[] = $coatingId;
            }
        }

        // На каждое выжившее покрытие: худший грейд, минимальная температура, разбивка по веществам.
        $rows = [];
        foreach ($coatingIds as $coatingId) {
            $worst = null;
            $minTemp = null;
            $verdicts = [];
            foreach ($refs as $ref) {
                $rc = $perSubstance[$ref->id][$coatingId];
                $grade = Grade::from($rc->grade);
                if (null === $worst || $this->weight($grade) > $this->weight($worst)) {
                    $worst = $grade;
                }
                $minTemp = null === $minTemp ? $rc->maxTemperature : min($minTemp, $rc->maxTemperature);
                $verdicts[] = new SubstanceVerdictDTO(
                    substanceId: $ref->id,
                    substanceName: $ref->canonicalName,
                    grade: $rc->grade,
                    gradeLabel: $rc->gradeLabel,
                    maxTemperature: $rc->maxTemperature,
                    assessmentId: $rc->assessmentId,
                );
            }
            $rows[$coatingId] = ['worst' => $worst, 'minTemp' => $minTemp, 'verdicts' => $verdicts];
        }

        // Стойкие (лучший худший-вердикт) — вперёд.
        usort($coatingIds, fn (string $a, string $b) => $this->weight($rows[$a]['worst']) <=> $this->weight($rows[$b]['worst']));

        $total = count($coatingIds);
        $offset = max(0, ($query->page - 1) * $query->perPage);
        $pageIds = array_slice($coatingIds, $offset, $query->perPage);

        $items = [];
        if ([] !== $pageIds) {
            $dtosById = [];
            foreach ($this->coatingDTOTransformer->fromEntityList(
                $this->coatingRepository->findByIds(new StringCollection(...$pageIds))
            ) as $dto) {
                $dtosById[$dto->id] = $dto;
            }
            foreach ($pageIds as $coatingId) {
                if (!isset($dtosById[$coatingId])) {
                    continue;
                }
                $row = $rows[$coatingId];
                $items[] = new CoatingResistanceDTO(
                    coating: $dtosById[$coatingId],
                    grade: $row['worst']->value,
                    gradeLabel: $row['worst']->label(),
                    maxTemperature: $row['minTemp'],
                    verdicts: $row['verdicts'],
                );
            }
        }

        return new GetCoatingsBySubstanceQueryResult($items, new Pager($query->page, $query->perPage, $total), $refs);
    }

    /** Вес грейда для сортировки/худшего вердикта: меньше = стойче (R лучший). */
    private function weight(Grade $grade): int
    {
        return match ($grade) {
            Grade::R => 0,
            Grade::LR => 1,
            Grade::NR => 2,
            default => 3,
        };
    }
}
