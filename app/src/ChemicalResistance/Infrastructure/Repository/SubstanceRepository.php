<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class SubstanceRepository extends ServiceEntityRepository implements SubstanceRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Substance::class);
    }

    public function add(Substance $s): void
    {
        $this->getEntityManager()->persist($s);
        $this->getEntityManager()->flush();
    }

    public function remove(Substance $s): void
    {
        $this->getEntityManager()->remove($s);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Substance
    {
        return $this->find($id);
    }

    public function findByCanonicalNameKey(string $key): ?Substance
    {
        return $this->createQueryBuilder('s')
            ->where('s.canonicalNameKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCas(CasNumber $cas): ?Substance
    {
        return $this->createQueryBuilder('s')
            ->where('s.cas = :cas')
            ->setParameter('cas', $cas->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<string> $ids
     * @return list<Substance>
     */
    public function findAllByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<Substance> $substances */
        $substances = $this->createQueryBuilder('s')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($substances as $s) {
            $byId[$s->getId()] = $s;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }
        return $ordered;
    }

    public function findByFilter(SubstancesFilter $filter): PaginationResult
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = '1=1';
        $params = [];
        $types = [];

        if ($filter->search !== null && trim($filter->search) !== '') {
            $like = '%' . trim($filter->search) . '%';
            $where = "(s.canonical_name ILIKE :search
                OR s.cas ILIKE :search
                OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(s.aliases) v WHERE v ILIKE :search))";
            $params['search'] = $like;
        }

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM chemical_resistance_substance s WHERE {$where}",
            $params,
            $types,
        );

        $dataSql = "SELECT s.id::text AS id, s.canonical_name, s.cas, s.aliases::text AS aliases
                    FROM chemical_resistance_substance s
                    WHERE {$where}
                    ORDER BY s.canonical_name ASC";

        if ($filter->pager !== null) {
            $dataSql .= ' LIMIT :lim OFFSET :off';
            $params['lim'] = $filter->pager->getLimit();
            $params['off'] = $filter->pager->getOffset();
            $types['lim'] = ParameterType::INTEGER;
            $types['off'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($dataSql, $params, $types);

        $items = array_map(fn(array $r) => new SubstanceDTO(
            id: $r['id'],
            canonicalName: $r['canonical_name'],
            cas: $r['cas'],
            aliases: json_decode($r['aliases'], true) ?: [],
        ), $rows);

        return new PaginationResult($items, $total);
    }
}
