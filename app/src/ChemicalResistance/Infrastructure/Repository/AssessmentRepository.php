<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Assessment\Assessment;
use App\ChemicalResistance\Domain\Repository\AssessmentRepositoryInterface;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class AssessmentRepository extends ServiceEntityRepository implements AssessmentRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Assessment::class);
    }

    public function add(Assessment $a): void
    {
        $this->getEntityManager()->persist($a);
        $this->getEntityManager()->flush();
    }

    public function remove(Assessment $a): void
    {
        $this->getEntityManager()->remove($a);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Assessment
    {
        return $this->find($id);
    }

    public function findByCoatingAndSubstance(Uuid $coatingId, Uuid $substanceId): ?Assessment
    {
        return $this->createQueryBuilder('a')
            ->where('a.coatingId = :coatingId')
            ->andWhere('a.substanceId = :substanceId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->setParameter('substanceId', $substanceId->toRfc4122())
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return list<Assessment> */
    public function findAllByCoating(Uuid $coatingId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.coatingId = :coatingId')
            ->setParameter('coatingId', $coatingId->toRfc4122())
            ->getQuery()
            ->getResult();
    }

    /** @return list<Assessment> */
    public function findAllBySubstance(Uuid $substanceId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.substanceId = :substanceId')
            ->setParameter('substanceId', $substanceId->toRfc4122())
            ->getQuery()
            ->getResult();
    }

    public function countAssessmentsWithNoteId(string $noteId): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM chemical_resistance_assessment WHERE note_ids @> :id::jsonb',
            ['id' => json_encode([$noteId])],
        );

        return (int) $count;
    }

    public function paginateByCoating(Uuid $coatingId, ?string $search, int $page, int $pageSize): PaginationResult
    {
        $conn = $this->getEntityManager()->getConnection();
        $cidStr = $coatingId->toRfc4122();
        $offset = ($page - 1) * $pageSize;

        $baseWhere = 'a.coating_id = :cid';
        $baseParams = ['cid' => $cidStr];

        if (null !== $search && '' !== $search) {
            $baseWhere .= '
                AND (
                    s.canonical_name ILIKE :search
                    OR s.cas ILIKE :search
                    OR EXISTS (
                        SELECT 1
                        FROM jsonb_array_elements_text(s.aliases) AS alias_val
                        WHERE alias_val ILIKE :search
                    )
                )';
            $baseParams['search'] = '%'.$search.'%';
        }

        $countSql = "
            SELECT COUNT(*)
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance s ON s.id = a.substance_id
            WHERE {$baseWhere}
        ";
        $total = (int) $conn->fetchOne($countSql, $baseParams);

        if (0 === $total) {
            return new PaginationResult([], 0);
        }

        $pageSql = "
            SELECT a.id
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance s ON s.id = a.substance_id
            WHERE {$baseWhere}
            ORDER BY s.canonical_name ASC
            LIMIT :limit OFFSET :offset
        ";
        $pageParams = $baseParams;
        $pageParams['limit'] = $pageSize;
        $pageParams['offset'] = $offset;

        $rows = $conn->fetchAllAssociative($pageSql, $pageParams);
        $ids = array_column($rows, 'id');

        if ([] === $ids) {
            return new PaginationResult([], $total);
        }

        /** @var list<Assessment> $unordered */
        $unordered = $this->createQueryBuilder('a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($unordered as $a) {
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
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
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
