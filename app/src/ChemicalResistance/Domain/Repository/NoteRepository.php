<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Note\Note;
use App\Shared\Domain\Repository\PaginationResult;
use Symfony\Component\Uid\Uuid;

interface NoteRepository
{
    public function save(Note $note): void;
    public function remove(Note $note): void;
    public function find(Uuid $id): ?Note;

    /**
     * @param list<string> $ids UUIDs as strings
     * @return list<Note>       ordered as $ids; missing ids silently skipped
     */
    public function findAllByIds(array $ids): array;

    public function findByFilter(NotesFilter $filter): PaginationResult;
}
