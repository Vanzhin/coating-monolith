<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Repository;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\ChemicalResistance\Domain\Repository\NoteRepository;
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
}
