<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Repository;

use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class SurfaceTreatmentRepository implements SurfaceTreatmentRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(SurfaceTreatment $t): void
    {
        $this->em->persist($t);
        $this->em->flush();
    }

    public function remove(SurfaceTreatment $t): void
    {
        $this->em->remove($t);
        $this->em->flush();
    }

    public function findById(Uuid $id): ?SurfaceTreatment
    {
        return $this->em->find(SurfaceTreatment::class, $id);
    }

    /** @return list<SurfaceTreatment> */
    public function list(SurfaceTreatmentsFilter $filter, int $limit, int $offset): array
    {
        $ids = $this->fetchIds($filter, $limit, $offset);
        if ([] === $ids) {
            return [];
        }

        /** @var list<SurfaceTreatment> */
        return $this->em->createQueryBuilder()
            ->select('t')
            ->from(SurfaceTreatment::class, 't')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('t.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function count(SurfaceTreatmentsFilter $filter): int
    {
        [$sql, $params] = $this->buildBaseQuery($filter);
        $sql = 'SELECT COUNT(*) FROM ('.$sql.') sub';

        return (int) $this->em->getConnection()->fetchOne($sql, $params);
    }

    /** @return list<string> */
    private function fetchIds(SurfaceTreatmentsFilter $filter, int $limit, int $offset): array
    {
        [$sql, $params] = $this->buildBaseQuery($filter);
        $sql .= ' ORDER BY updated_at DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return array_column(
            $this->em->getConnection()->fetchAllAssociative($sql, $params),
            'id',
        );
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildBaseQuery(SurfaceTreatmentsFilter $filter): array
    {
        $sql = 'SELECT id, updated_at FROM surface_treatment WHERE 1=1';
        $params = [];

        if (null !== $filter->substrate) {
            $sql .= ' AND jsonb_exists(substrate_scope, :substrate)';
            $params['substrate'] = $filter->substrate->value;
        }

        if (null !== $filter->q && mb_strlen(trim($filter->q)) >= 2) {
            $sql .= ' AND (LOWER(code) LIKE LOWER(:q) OR LOWER(description) LIKE LOWER(:q))';
            $params['q'] = '%'.trim($filter->q).'%';
        }

        return [$sql, $params];
    }
}
