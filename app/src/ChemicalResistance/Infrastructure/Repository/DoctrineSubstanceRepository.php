<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueCasSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueSubstanceNameSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepository;
use App\ChemicalResistance\Domain\Repository\SubstancesFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineSubstanceRepository implements SubstanceRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function makeSpec(): SubstanceSpecification
    {
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($this),
            new UniqueCasSpecification($this),
        );
    }

    public function save(Substance $s): void
    {
        $this->em->persist($s);
        $this->em->flush();
    }

    public function remove(Substance $s): void
    {
        $this->em->remove($s);
        $this->em->flush();
    }

    public function find(Uuid $id): ?Substance
    {
        $s = $this->em->find(Substance::class, $id);
        if ($s !== null) {
            $s->setSpecification($this->makeSpec());
        }
        return $s;
    }

    public function findByCanonicalNameKey(string $key): ?Substance
    {
        /** @var ?Substance $s */
        $s = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Substance::class, 's')
            ->where('s.canonicalNameKey = :key')
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();

        if ($s !== null) {
            $s->setSpecification($this->makeSpec());
        }
        return $s;
    }

    public function findByCas(CasNumber $cas): ?Substance
    {
        /** @var ?Substance $s */
        $s = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Substance::class, 's')
            ->where('s.cas = :cas')
            ->setParameter('cas', $cas->value)
            ->getQuery()
            ->getOneOrNullResult();

        if ($s !== null) {
            $s->setSpecification($this->makeSpec());
        }
        return $s;
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
        $substances = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Substance::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $spec = $this->makeSpec();
        /** @var array<string, Substance> $byId */
        $byId = [];
        foreach ($substances as $s) {
            $s->setSpecification($spec);
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
        $conn = $this->em->getConnection();

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

        $countSql = "SELECT COUNT(*) FROM chemical_resistance_substance s WHERE {$where}";
        $total = (int) $conn->fetchOne($countSql, $params, $types);

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

        $items = array_map(fn(array $r) => new \App\ChemicalResistance\Application\DTO\SubstanceDTO(
            id: $r['id'],
            canonicalName: $r['canonical_name'],
            cas: $r['cas'],
            aliases: json_decode($r['aliases'], true) ?: [],
        ), $rows);

        return new PaginationResult($items, $total);
    }
}
