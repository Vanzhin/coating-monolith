<?php
declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\GetSubstance;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use App\Shared\Application\Query\QueryHandlerInterface;
use Doctrine\DBAL\Connection;

class GetSubstanceQueryHandler implements QueryHandlerInterface
{
    public function __construct(private readonly Connection $dbal) {}

    public function __invoke(GetSubstanceQuery $query): GetSubstanceQueryResult
    {
        $row = $this->dbal->fetchAssociative(
            "SELECT id::text AS id, canonical_name, cas, aliases::text AS aliases
             FROM chemical_resistance_substance
             WHERE id = :id::uuid",
            ['id' => $query->id],
        );

        if ($row === false) {
            return new GetSubstanceQueryResult(null);
        }

        return new GetSubstanceQueryResult(new SubstanceDTO(
            id: $row['id'],
            canonicalName: $row['canonical_name'],
            cas: $row['cas'],
            aliases: json_decode($row['aliases'], true) ?: [],
        ));
    }
}
