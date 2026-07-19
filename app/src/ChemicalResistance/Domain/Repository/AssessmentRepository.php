<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\Shared\Domain\Repository\PaginationResult;
use Symfony\Component\Uid\Uuid;

interface AssessmentRepository
{
    public function save(Assessment $a): void;
    public function remove(Assessment $a): void;
    public function find(Uuid $id): ?Assessment;
    public function findByCoatingAndSubstance(Uuid $coatingId, Uuid $substanceId): ?Assessment;
    /** @return list<Assessment> */
    public function findAllByCoating(Uuid $coatingId): array;
    /** @return list<Assessment> */
    public function findAllBySubstance(Uuid $substanceId): array;
    public function paginateByCoating(Uuid $coatingId, ?string $search, int $page, int $pageSize): PaginationResult;
    public function countAssessmentsWithNoteId(string $noteId): int;
    /** @return array<string, int> keyed by grade code, e.g. ['R' => 823, 'LR' => 42] */
    public function countByCoatingGroupedByGrade(Uuid $coatingId): array;
}
