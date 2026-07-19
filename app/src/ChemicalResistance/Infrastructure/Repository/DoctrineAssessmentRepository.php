<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentNotesConsistencyValidator;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\AssessmentSpecification;
use App\ChemicalResistance\Domain\Aggregate\Assessment\Specification\UniqueCoatingSubstanceAssessmentSpecification;
use App\ChemicalResistance\Domain\Repository\AssessmentRepository;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineAssessmentRepository implements AssessmentRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NoteRepository $notes,
    ) {}

    public function makeSpec(): AssessmentSpecification
    {
        return new AssessmentSpecification(
            new UniqueCoatingSubstanceAssessmentSpecification($this),
            new AssessmentNotesConsistencyValidator(),
        );
    }

    private function reinject(Assessment $a): void
    {
        $a->setSpecification($this->makeSpec());
        $a->setNotesRepositoryForConsistency($this->notes);
    }

    public function save(Assessment $a): void
    {
        $this->em->persist($a);
        $this->em->flush();
    }

    public function remove(Assessment $a): void
    {
        $this->em->remove($a);
        $this->em->flush();
    }

    public function find(Uuid $id): ?Assessment
    {
        $a = $this->em->find(Assessment::class, $id);
        if ($a !== null) {
            $this->reinject($a);
        }
        return $a;
    }

    public function findByCoatingAndSubstance(Uuid $coatingId, Uuid $substanceId): ?Assessment
    {
        /** @var ?Assessment $a */
        $a = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.coatingId = :coatingId')
            ->andWhere('a.substanceId = :substanceId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->setParameter('substanceId', $substanceId->toRfc4122())
            ->getQuery()
            ->getOneOrNullResult();

        if ($a !== null) {
            $this->reinject($a);
        }
        return $a;
    }

    /**
     * @return list<Assessment>
     */
    public function findAllByCoating(Uuid $coatingId): array
    {
        /** @var list<Assessment> $assessments */
        $assessments = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.coatingId = :coatingId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->getQuery()
            ->getResult();

        foreach ($assessments as $a) {
            $this->reinject($a);
        }
        return $assessments;
    }

    /**
     * @return list<Assessment>
     */
    public function findAllBySubstance(Uuid $substanceId): array
    {
        /** @var list<Assessment> $assessments */
        $assessments = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.substanceId = :substanceId')
            ->setParameter('substanceId', $substanceId->toRfc4122())
            ->getQuery()
            ->getResult();

        foreach ($assessments as $a) {
            $this->reinject($a);
        }
        return $assessments;
    }

    public function countAssessmentsWithNoteId(string $noteId): int
    {
        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM chemical_resistance_assessment WHERE note_ids @> :id::jsonb',
            ['id' => json_encode([$noteId])],
        );
        return (int) $count;
    }

    public function paginateByCoating(Uuid $coatingId, ?string $search, int $page, int $pageSize): PaginationResult
    {
        $conn   = $this->em->getConnection();
        $cidStr = $coatingId->toRfc4122();
        $offset = ($page - 1) * $pageSize;

        $baseWhere = 'a.coating_id = :cid';
        $baseParams = ['cid' => $cidStr];

        if ($search !== null && $search !== '') {
            $baseWhere .= "
                AND (
                    s.canonical_name ILIKE :search
                    OR s.cas ILIKE :search
                    OR EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements_text(s.aliases) AS alias_val
                        WHERE alias_val ILIKE :search
                    )
                )";
            $baseParams['search'] = '%' . $search . '%';
        }

        // Total count (no LIMIT/OFFSET).
        $countSql = "
            SELECT COUNT(*)
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance s ON s.id = a.substance_id
            WHERE {$baseWhere}
        ";
        $total = (int) $conn->fetchOne($countSql, $baseParams);

        if ($total === 0) {
            return new PaginationResult([], 0);
        }

        // Fetch page of IDs ordered by substance name.
        $pageSql = "
            SELECT a.id
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance s ON s.id = a.substance_id
            WHERE {$baseWhere}
            ORDER BY s.canonical_name ASC
            LIMIT :limit OFFSET :offset
        ";
        $pageParams           = $baseParams;
        $pageParams['limit']  = $pageSize;
        $pageParams['offset'] = $offset;

        $rows = $conn->fetchAllAssociative($pageSql, $pageParams);
        $ids  = array_column($rows, 'id');

        if ($ids === []) {
            return new PaginationResult([], $total);
        }

        // Load Assessment entities, then re-order to match SQL result order.
        /** @var list<Assessment> $unordered */
        $unordered = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Assessment::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($unordered as $a) {
            $this->reinject($a);
            $byId[$a->getId()] = $a;
        }
        $items = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $items[] = $byId[$id];
            }
        }

        return new PaginationResult($items, $total);
    }

    /** @return array<string, int> */
    public function countByCoatingGroupedByGrade(Uuid $coatingId): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT grade, COUNT(*) AS cnt
             FROM chemical_resistance_assessment
             WHERE coating_id = :cid
             GROUP BY grade',
            ['cid' => $coatingId->toRfc4122()],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['grade']] = (int) $r['cnt'];
        }
        return $out;
    }
}
