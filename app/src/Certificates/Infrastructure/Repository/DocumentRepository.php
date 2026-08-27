<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Repository;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Repository\DocumentExpiryStatus;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Certificates\Domain\Repository\DocumentSort;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Database\FullTextSearch\PrefixTsQueryBuilder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class DocumentRepository extends ServiceEntityRepository implements DocumentRepositoryInterface
{
    private const FTS_LANG = 'russian';

    public function __construct(
        ManagerRegistry $registry,
        private readonly PrefixTsQueryBuilder $tsQueryBuilder,
    ) {
        parent::__construct($registry, Document::class);
    }

    public function add(Document $document): void
    {
        $this->getEntityManager()->persist($document);
        $this->getEntityManager()->flush();
    }

    public function remove(Document $document): void
    {
        $this->getEntityManager()->remove($document);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Document
    {
        return $this->findOneBy(['id' => $id]);
    }

    public function findByFilter(DocumentsFilter $filter): PaginationResult
    {
        $conn = $this->getEntityManager()->getConnection();
        $qb = $conn->createQueryBuilder()->select('d.id')->from('certificates_document', 'd');

        $tsquery = $this->applyFts($qb, $filter);
        $this->applyReference($qb, $filter);
        $this->applyKind($qb, $filter);
        $this->applyIssuer($qb, $filter);
        $this->applyStatus($qb, $filter);
        $this->applyTestStandard($qb, $filter);
        $this->applyCoatings($qb, $filter);

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(*)')->executeQuery()->fetchOne();

        $this->applySort($qb, $filter, null !== $tsquery);

        if (null !== $filter->pager) {
            $qb->setFirstResult($filter->pager->getOffset())->setMaxResults($filter->pager->getLimit());
        }

        $ids = array_map('strval', $qb->executeQuery()->fetchFirstColumn());

        return new PaginationResult($this->hydrateOrdered($ids), $total);
    }

    public function findByReference(Reference $reference): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $ids = $conn->createQueryBuilder()
            ->select('id')
            ->from('certificates_document')
            ->where('owner_refs @> CAST(:ref AS jsonb)')
            ->setParameter('ref', json_encode([$reference->jsonSerialize()], JSON_THROW_ON_ERROR))
            ->orderBy('issued_at', 'DESC')
            ->executeQuery()
            ->fetchFirstColumn();

        return $this->hydrateOrdered(array_map('strval', $ids));
    }

    public function countByReferences(ReferenceType $type, StringCollection $referenceIds): array
    {
        if (0 === $referenceIds->count()) {
            return [];
        }

        $sql = <<<'SQL'
            SELECT elem->>'id' AS rid, COUNT(*) AS cnt
            FROM certificates_document d, jsonb_array_elements(d.owner_refs) elem
            WHERE elem->>'type' = :type AND elem->>'id' IN (:ids)
            GROUP BY rid
        SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['type' => $type->value, 'ids' => $referenceIds->getList()],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['rid']] = (int) $row['cnt'];
        }

        return $result;
    }

    public function downloadableByReferences(ReferenceType $type, StringCollection $referenceIds): array
    {
        if (0 === $referenceIds->count()) {
            return [];
        }

        // DISTINCT ON (владелец) + ORDER BY дате — один самый свежий скачиваемый документ на систему.
        $sql = <<<'SQL'
            SELECT DISTINCT ON (elem->>'id') elem->>'id' AS rid, d.id::text AS doc_id
            FROM certificates_document d, jsonb_array_elements(d.owner_refs) elem
            WHERE elem->>'type' = :type AND elem->>'id' IN (:ids) AND d.file IS NOT NULL
            ORDER BY elem->>'id', d.issued_at DESC
        SQL;

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            $sql,
            ['type' => $type->value, 'ids' => $referenceIds->getList()],
            ['ids' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['rid']] = (string) $row['doc_id'];
        }

        return $result;
    }

    private function applyFts(QueryBuilder $qb, DocumentsFilter $filter): ?string
    {
        if (null === $filter->query || '' === trim($filter->query)) {
            return null;
        }

        $tsquery = $this->tsQueryBuilder->build($filter->query, PrefixTsQueryBuilder::CONJUNCTION_AND);
        if ('' === $tsquery) {
            $qb->andWhere('1 = 0');

            return null;
        }

        $qb->andWhere('d.search_tsvector @@ TO_TSQUERY(:fts_lang, :fts_tsquery)')
            ->setParameter('fts_lang', self::FTS_LANG)
            ->setParameter('fts_tsquery', $tsquery);

        return $tsquery;
    }

    private function applyReference(QueryBuilder $qb, DocumentsFilter $filter): void
    {
        if (null === $filter->reference) {
            return;
        }

        $qb->andWhere('d.owner_refs @> CAST(:ref AS jsonb)')
            ->setParameter('ref', json_encode([$filter->reference->jsonSerialize()], JSON_THROW_ON_ERROR));
    }

    private function applyKind(QueryBuilder $qb, DocumentsFilter $filter): void
    {
        if (null === $filter->kind) {
            return;
        }

        $qb->andWhere('d.kind = :kind')->setParameter('kind', $filter->kind->value);
    }

    private function applyIssuer(QueryBuilder $qb, DocumentsFilter $filter): void
    {
        if (null === $filter->issuerId) {
            return;
        }

        $qb->andWhere('d.issuer_id = CAST(:issuer AS uuid)')->setParameter('issuer', $filter->issuerId);
    }

    private function applyStatus(QueryBuilder $qb, DocumentsFilter $filter): void
    {
        // NOW(): дата истекающая сегодня считается просроченной — как в Document::isExpired.
        match ($filter->status) {
            DocumentExpiryStatus::Expired => $qb->andWhere('d.expires_at IS NOT NULL AND d.expires_at < NOW()'),
            DocumentExpiryStatus::Valid => $qb->andWhere('d.expires_at IS NOT NULL AND d.expires_at >= NOW()'),
            DocumentExpiryStatus::Perpetual => $qb->andWhere('d.expires_at IS NULL'),
            null => null,
        };
    }

    private function applyTestStandard(QueryBuilder $qb, DocumentsFilter $filter): void
    {
        if (null === $filter->testStandard) {
            return;
        }

        $qb->andWhere('d.test_standard = :ts')->setParameter('ts', $filter->testStandard);
    }

    /**
     * Документы, связанные с указанными покрытиями напрямую (owner_refs с type=coating)
     * ИЛИ косвенно — через систему, привязанную к документу, у которой это покрытие в слоях
     * (document → system → coating_system_layer). Джойн к таблице слоёв — read-side связь на
     * уровне БД (код Coatings не импортируем, цикла зависимостей нет).
     */
    private function applyCoatings(QueryBuilder $qb, DocumentsFilter $filter): void
    {
        if (null === $filter->coatingIds || 0 === $filter->coatingIds->count()) {
            return;
        }

        $qb->andWhere(<<<'SQL'
            (
                EXISTS (
                    SELECT 1 FROM jsonb_array_elements(d.owner_refs) elem
                    WHERE elem->>'type' = :ref_coating AND elem->>'id' IN (:coating_ids_direct)
                )
                OR EXISTS (
                    SELECT 1 FROM jsonb_array_elements(d.owner_refs) elem
                    JOIN coating_system_layer csl ON csl.system_id::text = elem->>'id'
                    WHERE elem->>'type' = :ref_system AND csl.coating_id::text IN (:coating_ids_layer)
                )
            )
            SQL)
            ->setParameter('ref_coating', ReferenceType::Coating->value)
            ->setParameter('ref_system', ReferenceType::CoatingSystem->value)
            ->setParameter('coating_ids_direct', $filter->coatingIds->getList(), ArrayParameterType::STRING)
            ->setParameter('coating_ids_layer', $filter->coatingIds->getList(), ArrayParameterType::STRING);
    }

    private function applySort(QueryBuilder $qb, DocumentsFilter $filter, bool $hasFts): void
    {
        switch ($filter->sort) {
            case DocumentSort::ISSUER_ASC:
                $qb->leftJoin('d', 'certificates_issuer', 'iss', 'iss.id = d.issuer_id')
                    ->orderBy('iss.title', 'ASC')
                    ->addOrderBy('d.issued_at', 'DESC');
                break;
            case DocumentSort::TITLE_ASC:
                $qb->orderBy('d.title', 'ASC');
                break;
            case DocumentSort::DATE_DESC:
                $qb->orderBy('d.issued_at', 'DESC');
                break;
            default: // DEFAULT: ранк при FTS, иначе свежие сначала.
                if ($hasFts) {
                    $qb->addSelect('TS_RANK_CD(d.search_tsvector, TO_TSQUERY(:fts_lang, :fts_tsquery)) AS rank')
                        ->orderBy('rank', 'DESC')
                        ->addOrderBy('d.issued_at', 'DESC');
                } else {
                    $qb->orderBy('d.issued_at', 'DESC');
                }
        }
    }

    /**
     * @return list<string>
     */
    public function distinctTestStandards(): array
    {
        $rows = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('DISTINCT d.test_standard')
            ->from('certificates_document', 'd')
            ->where('d.test_standard IS NOT NULL')
            ->andWhere("d.test_standard <> ''")
            ->orderBy('d.test_standard', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    /**
     * @return list<string>
     */
    public function existingTitles(): array
    {
        $rows = $this->getEntityManager()->getConnection()->createQueryBuilder()
            ->select('d.title')
            ->from('certificates_document', 'd')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('strval', $rows);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Document>
     */
    private function hydrateOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $byId = [];
        foreach ($this->findBy(['id' => $ids]) as $document) {
            $byId[$document->getId()] = $document;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
