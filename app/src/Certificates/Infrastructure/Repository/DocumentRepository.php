<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Repository;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\DocumentsFilter;
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

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(*)')->executeQuery()->fetchOne();

        if (null !== $tsquery) {
            $qb->addSelect('TS_RANK_CD(d.search_tsvector, TO_TSQUERY(:fts_lang, :fts_tsquery)) AS rank')
                ->orderBy('rank', 'DESC')
                ->addOrderBy('d.issued_at', 'DESC');
        } else {
            $qb->orderBy('d.issued_at', 'DESC');
        }

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
