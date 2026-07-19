<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Application\DTO\NoteDTO;
use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
use App\ChemicalResistance\Domain\Repository\NotesFilter;
use App\Shared\Domain\Repository\PaginationResult;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class DoctrineNoteRepository implements NoteRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(Note $note): void
    {
        $this->em->persist($note);
        $this->em->flush();
    }

    public function remove(Note $note): void
    {
        $this->em->remove($note);
        $this->em->flush();
    }

    public function find(Uuid $id): ?Note
    {
        return $this->em->find(Note::class, $id);
    }

    /**
     * @param list<string> $ids UUIDs as strings
     * @return list<Note>
     */
    public function findAllByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var list<Note> $notes */
        $notes = $this->em->createQueryBuilder()
            ->select('n')
            ->from(Note::class, 'n')
            ->where('n.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        /** @var array<string, Note> $byId */
        $byId = [];
        foreach ($notes as $note) {
            $byId[$note->getId()] = $note;
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
        $conn = $this->em->getConnection();

        $where = '1=1';
        $params = [];
        $types = [];

        if ($filter->search !== null && trim($filter->search) !== '') {
            $like = '%' . trim($filter->search) . '%';
            $where = "(n.title ILIKE :search OR n.description ILIKE :search)";
            $params['search'] = $like;
        }

        $countSql = "SELECT COUNT(*) FROM chemical_resistance_note n WHERE {$where}";
        $total = (int) $conn->fetchOne($countSql, $params, $types);

        $dataSql = "SELECT n.id::text AS id, n.title, n.description
                    FROM chemical_resistance_note n
                    WHERE {$where}
                    ORDER BY n.title ASC";

        if ($filter->pager !== null) {
            $dataSql .= ' LIMIT :lim OFFSET :off';
            $params['lim'] = $filter->pager->getLimit();
            $params['off'] = $filter->pager->getOffset();
            $types['lim'] = ParameterType::INTEGER;
            $types['off'] = ParameterType::INTEGER;
        }

        $rows = $conn->fetchAllAssociative($dataSql, $params, $types);

        $items = array_map(fn(array $r) => new NoteDTO(
            id: $r['id'],
            title: $r['title'],
            description: $r['description'],
        ), $rows);

        return new PaginationResult($items, $total);
    }
}
