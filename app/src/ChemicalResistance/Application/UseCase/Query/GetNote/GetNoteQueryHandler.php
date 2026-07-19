<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetNote;

use App\ChemicalResistance\Application\DTO\NoteDTO;
use App\Shared\Application\Query\QueryHandlerInterface;
use Doctrine\DBAL\Connection;

class GetNoteQueryHandler implements QueryHandlerInterface
{
    public function __construct(private readonly Connection $dbal)
    {
    }

    public function __invoke(GetNoteQuery $query): GetNoteQueryResult
    {
        $row = $this->dbal->fetchAssociative(
            'SELECT id::text AS id, title, description
             FROM chemical_resistance_note
             WHERE id = :id::uuid',
            ['id' => $query->id],
        );

        if (false === $row) {
            return new GetNoteQueryResult(null);
        }

        return new GetNoteQueryResult(new NoteDTO(
            id: $row['id'],
            title: $row['title'],
            description: $row['description'],
        ));
    }
}
