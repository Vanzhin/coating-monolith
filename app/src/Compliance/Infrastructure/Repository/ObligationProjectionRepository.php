<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Repository\ObligationProjectionFilter;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Читает проекцию через DQL на read-only {@see ObligationProjection} (ORM её не персистит), пишет —
 * DBAL-upsert'ом напрямую в `compliance_obligation_projection` (образец —
 * {@see \App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository::upsert}). ORM и DBAL здесь
 * намеренно разведены по направлению: читатель не видит незафиксированных через `upsertMany` строк в рамках
 * той же транзакции только если EntityManager их уже закэшировал в identity map — вызывающему на функциональных
 * тестах стоит звать `EntityManagerInterface::clear()` между upsert и чтением той же строки.
 */
final class ObligationProjectionRepository implements ObligationProjectionRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $conn,
    ) {
    }

    public function findByFilter(ObligationProjectionFilter $filter): PaginationResult
    {
        $pager = $filter->pager;
        $qb = $this->filteredQueryBuilder($filter);

        $total = (int) (clone $qb)
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $qb)
            ->select('o')
            ->orderBy('o.profileId', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setFirstResult($pager->getOffset())
            ->setMaxResults($pager->getLimit())
            ->getQuery()
            ->getResult();

        return new PaginationResult($items, $total);
    }

    public function findForProfiles(StringCollection $profileIds): array
    {
        if (0 === $profileIds->count()) {
            return [];
        }

        return $this->em->createQueryBuilder()
            ->select('o')
            ->from(ObligationProjection::class, 'o')
            ->where('o.profileId IN (:profileIds)')
            ->setParameter('profileIds', $profileIds->getList())
            ->getQuery()
            ->getResult();
    }

    public function upsertMany(iterable $rows): void
    {
        foreach ($rows as $row) {
            $this->conn->executeStatement(
                <<<'SQL'
                    INSERT INTO compliance_obligation_projection
                        (id, profile_id, requirement_id, item_id, requirement_name, label, type, cadence,
                         cadence_struct, quantity, quantity_unit, department_id, last_fulfilled_at, next_due_at,
                         held_quantity, active, origin, mode)
                    VALUES
                        (:id, :profile_id, :requirement_id, :item_id, :requirement_name, :label, :type, :cadence,
                         :cadence_struct, :quantity, :quantity_unit, :department_id, :last_fulfilled_at, :next_due_at,
                         :held_quantity, :active, :origin, :mode)
                    ON CONFLICT (profile_id, requirement_id, item_id) DO UPDATE
                    SET requirement_name  = EXCLUDED.requirement_name,
                        label             = EXCLUDED.label,
                        type              = EXCLUDED.type,
                        cadence           = EXCLUDED.cadence,
                        cadence_struct    = EXCLUDED.cadence_struct,
                        quantity          = EXCLUDED.quantity,
                        quantity_unit     = EXCLUDED.quantity_unit,
                        department_id     = EXCLUDED.department_id,
                        last_fulfilled_at = EXCLUDED.last_fulfilled_at,
                        next_due_at       = EXCLUDED.next_due_at,
                        held_quantity     = EXCLUDED.held_quantity,
                        active            = EXCLUDED.active,
                        origin            = EXCLUDED.origin,
                        mode              = EXCLUDED.mode
                    SQL,
                $row,
                // Без явного типа DBAL::bindParameters биндит ЛЮБОЙ параметр без записи в $types как
                // ParameterType::STRING (нет авто-инференса по PHP-типу значения) — PDO кастит bool false
                // в "" (пустую строку), а Postgres на boolean-колонке падает SQLSTATE 22P02. `true` живёт
                // случайно (кастится в "1", Postgres это парсит), поэтому баг не был заметен на active:true.
                ['active' => ParameterType::BOOLEAN],
            );
        }
    }

    public function deleteObsoleteForProfileRequirement(string $profileId, string $requirementId, StringCollection $keepItemIds): void
    {
        if (0 === $keepItemIds->count()) {
            $this->conn->executeStatement(
                'DELETE FROM compliance_obligation_projection WHERE profile_id = :profileId AND requirement_id = :requirementId',
                ['profileId' => $profileId, 'requirementId' => $requirementId],
            );

            return;
        }

        $this->conn->executeStatement(
            'DELETE FROM compliance_obligation_projection
             WHERE profile_id = :profileId AND requirement_id = :requirementId AND item_id NOT IN (:keepItemIds)',
            ['profileId' => $profileId, 'requirementId' => $requirementId, 'keepItemIds' => $keepItemIds->getList()],
            ['keepItemIds' => ArrayParameterType::STRING],
        );
    }

    public function deleteForProfile(string $profileId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM compliance_obligation_projection WHERE profile_id = :profileId',
            ['profileId' => $profileId],
        );
    }

    private function filteredQueryBuilder(ObligationProjectionFilter $filter): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->from(ObligationProjection::class, 'o');

        if (null !== $filter->profileIds && $filter->profileIds->count() > 0) {
            $qb->andWhere('o.profileId IN (:profileIds)')->setParameter('profileIds', $filter->profileIds->getList());
        }
        if (null !== $filter->requirementId) {
            $qb->andWhere('o.requirementId = :requirementId')->setParameter('requirementId', $filter->requirementId);
        }
        if (null !== $filter->departmentId) {
            $qb->andWhere('o.departmentId = :departmentId')->setParameter('departmentId', $filter->departmentId);
        }
        if (null !== $filter->type) {
            $qb->andWhere('o.type = :type')->setParameter('type', $filter->type->value);
        }
        if (null !== $filter->active) {
            $qb->andWhere('o.active = :active')->setParameter('active', $filter->active);
        }
        if (null !== $filter->dueBefore) {
            $qb->andWhere('o.nextDueAt <= :dueBefore')->setParameter('dueBefore', $filter->dueBefore);
        }

        return $qb;
    }
}
