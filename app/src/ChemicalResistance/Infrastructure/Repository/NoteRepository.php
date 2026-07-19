<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Application\DTO\NoteDTO;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

class NoteRepository extends ServiceEntityRepository implements NoteRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    public function add(Note $note): void
    {
        $this->getEntityManager()->persist($note);
        $this->getEntityManager()->flush();
    }

    public function remove(Note $note): void
    {
        $this->getEntityManager()->remove($note);
        $this->getEntityManager()->flush();
    }

    public function findOneById(string $id): ?Note
    {
        return $this->find($id);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Note>
     */
    public function findAllByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<Note> $notes */
        $notes = $this->createQueryBuilder('n')
            ->where('n.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($notes as $n) {
            $byId[$n->getId()] = $n;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function findByFilter(NotesFilter $filter): PaginationResult
    {
        $conn = $this->getEntityManager()->getConnection();

        $where = '1=1';
        $params = [];
        $types = [];

        if (null !== $filter->search && '' !== trim($filter->search)) {
            $like = '%'.trim($filter->search).'%';
            $where = '(n.title ILIKE :search OR n.description ILIKE :search)';
            $params['search'] = $like;
        }

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM chemical_resistance_note n WHERE {$where}",
            $params,
            $types,
        );

        $dataSql = "SELECT n.id::text AS id, n.title, n.description
                    FROM chemical_resistance_note n
                    WHERE {$where}
                    ORDER BY n.title ASC";

        if (null !== $filter->pager) {
            $dataSql .= ' LIMIT :lim OFFSET :off';
            $params['lim'] = $filter->pager->getLimit();
            $params['off'] = $filter->pager->getOffset();
            $types['lim'] = ParameterType::INTEGER;
            $types['off'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($dataSql, $params, $types);

        $items = array_map(fn (array $r) => new NoteDTO(
            id: $r['id'],
            title: $r['title'],
            description: $r['description'],
        ), $rows);

        return new PaginationResult($items, $total);
    }
}
