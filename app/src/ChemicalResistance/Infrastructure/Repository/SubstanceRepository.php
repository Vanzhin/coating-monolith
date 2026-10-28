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
    // Порог триграммной похожести для fuzzy fallback — тот же приём, что в
    // CoatingFinder::fuzzyTitle, но НЕ тот же порог. CoatingFinder держит 0.6
    // на длинных английских заголовках покрытий; названия веществ короче и
    // часто кириллические — у них меньше триграмм, поэтому даже однобуквенная
    // опечатка в середине короткого слова («этонол» вместо «этанол», 6 букв)
    // даёт лишь WORD_SIMILARITY ≈ 0.4 — порог 0.6 отсёк бы её целиком.
    // Проверено на полном каталоге веществ (1762 канонических названия из
    // сид-данных ChemicalResistance): у мусорного запроса максимум ≈0.25,
    // у «этонол» лишь 2 кандидата проходят отметку 0.3 («Этанол» 0.4,
    // «2-Этоксиэтилацетат» 0.43) — шума от занижения порога не возникает.
    private const FUZZY_SIMILARITY_THRESHOLD = 0.35;

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
     *
     * @return list<Substance>
     */
    public function findAllByIds(array $ids): array
    {
        if ([] === $ids) {
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

        $whereParts = [];
        $params = [];
        $types = [];

        if (null !== $filter->search && '' !== trim($filter->search)) {
            $term = trim($filter->search);
            // Прямое: имя/алиас/CAS СОДЕРЖИТ запрос. Обратное: имя/алиас — слово ВНУТРИ запроса
            // («вода» ⊂ «деаэрированная вода»); короче 3 символов не берём, чтобы не ловить шум.
            $whereParts[] = '(s.canonical_name ILIKE :search
                OR s.cas ILIKE :search
                OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(s.aliases) v WHERE v ILIKE :search)
                OR (length(s.canonical_name) >= 3 AND :searchTerm ILIKE (\'%\' || s.canonical_name || \'%\'))
                OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(s.aliases) v WHERE length(v) >= 3 AND :searchTerm ILIKE (\'%\' || v || \'%\')))';
            $params['search'] = '%'.$term.'%';
            $params['searchTerm'] = $term;
        }

        if (null !== $filter->cas && '' !== trim($filter->cas)) {
            $whereParts[] = 's.cas ILIKE :cas';
            $params['cas'] = '%'.trim($filter->cas).'%';
        }

        $where = [] === $whereParts ? '1=1' : implode(' AND ', $whereParts);

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM chemical_resistance_substance s WHERE {$where}",
            $params,
            $types,
        );

        // Релевантность: точное совпадение имени/алиаса → префикс → прочая подстрока, потом алфавит.
        // Без этого «этанол» отдавал «Диэтаноламин» (подстрока + алфавит «Д»<«Э») вместо «Этанола».
        // Параметры ранжирования добавляем ПОСЛЕ COUNT — он их не использует.
        $order = 's.canonical_name ASC';
        if (null !== $filter->search && '' !== trim($filter->search)) {
            $term = trim($filter->search);
            $params['term'] = $term;
            $params['termPrefix'] = $term.'%';
            $order = 'CASE
                        WHEN LOWER(s.canonical_name) = LOWER(:term) THEN 0
                        WHEN EXISTS (SELECT 1 FROM jsonb_array_elements_text(s.aliases) v WHERE LOWER(v) = LOWER(:term)) THEN 1
                        WHEN s.canonical_name ILIKE :termPrefix THEN 2
                        WHEN EXISTS (SELECT 1 FROM jsonb_array_elements_text(s.aliases) v WHERE v ILIKE :termPrefix) THEN 3
                        ELSE 4
                      END, s.canonical_name ASC';
        }

        $dataSql = "SELECT s.id::text AS id, s.canonical_name, s.cas, s.aliases::text AS aliases
                    FROM chemical_resistance_substance s
                    WHERE {$where}
                    ORDER BY {$order}";

        if (null !== $filter->pager) {
            $dataSql .= ' LIMIT :lim OFFSET :off';
            $params['lim'] = $filter->pager->getLimit();
            $params['off'] = $filter->pager->getOffset();
            $types['lim'] = ParameterType::INTEGER;
            $types['off'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($dataSql, $params, $types);

        if (0 === $total && null !== $filter->search && '' !== trim($filter->search) && null === $filter->cas) {
            return $this->findByFuzzyName($filter);
        }

        return new PaginationResult($this->mapRows($rows), $total);
    }

    /**
     * Фаза 2: точный ILIKE ничего не нашёл — ищем по триграммной похожести
     * названия (опечатки типа «этонол» → «Этанол»). Порог и подход — как в
     * CoatingFinder::fuzzyTitle.
     *
     * GIN trgm-индекс ix_substance_canonical_trgm (Version20261120000001) тут
     * НЕ помогает: WORD_SIMILARITY(...) — вызов функции, а не `%`/`<%`-оператор
     * или ILIKE, поэтому индекс не используется и Postgres идёт полным сканом.
     * Индекс ускоряет только инфиксный ILIKE фазы 1 в findByFilter() выше.
     */
    private function findByFuzzyName(SubstancesFilter $filter): PaginationResult
    {
        $conn = $this->getEntityManager()->getConnection();
        $search = trim((string) $filter->search);

        $params = ['search' => $search, 'threshold' => self::FUZZY_SIMILARITY_THRESHOLD];
        $types = [];

        $total = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM chemical_resistance_substance s WHERE WORD_SIMILARITY(:search, s.canonical_name) > :threshold',
            $params,
            $types,
        );

        $dataSql = 'SELECT s.id::text AS id, s.canonical_name, s.cas, s.aliases::text AS aliases
                    FROM chemical_resistance_substance s
                    WHERE WORD_SIMILARITY(:search, s.canonical_name) > :threshold
                    ORDER BY WORD_SIMILARITY(:search, s.canonical_name) DESC';

        if (null !== $filter->pager) {
            $dataSql .= ' LIMIT :lim OFFSET :off';
            $params['lim'] = $filter->pager->getLimit();
            $params['off'] = $filter->pager->getOffset();
            $types['lim'] = ParameterType::INTEGER;
            $types['off'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($dataSql, $params, $types);

        return new PaginationResult($this->mapRows($rows), $total);
    }

    /**
     * @param list<array{id: string, canonical_name: string, cas: ?string, aliases: string}> $rows
     *
     * @return list<SubstanceDTO>
     */
    private function mapRows(array $rows): array
    {
        return array_map(fn (array $r) => new SubstanceDTO(
            id: $r['id'],
            canonicalName: $r['canonical_name'],
            cas: $r['cas'],
            aliases: json_decode($r['aliases'], true) ?: [],
        ), $rows);
    }
}
