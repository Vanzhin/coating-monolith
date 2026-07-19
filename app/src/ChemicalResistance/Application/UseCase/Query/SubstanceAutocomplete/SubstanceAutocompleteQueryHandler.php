<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\SubstanceAutocomplete;

use App\ChemicalResistance\Application\DTO\SubstanceDTO;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class SubstanceAutocompleteQueryHandler
{
    public function __construct(private Connection $dbal)
    {
    }

    /**
     * @return list<SubstanceDTO>
     */
    public function __invoke(SubstanceAutocompleteQuery $q): array
    {
        // Empty or whitespace-only query → early return
        if ('' === trim($q->q)) {
            return [];
        }

        $like = trim($q->q).'%';
        $exact = trim($q->q);

        $sql = '
            SELECT id::text AS id, canonical_name, cas, aliases::text AS aliases
            FROM chemical_resistance_substance
            WHERE canonical_name ILIKE :like
               OR cas = :exact
               OR EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) v WHERE v ILIKE :like)
            ORDER BY canonical_name ASC
            LIMIT :lim
        ';

        $rows = $this->dbal->fetchAllAssociative($sql, [
            'like' => $like,
            'exact' => $exact,
            'lim' => $q->limit,
        ], [
            'lim' => ParameterType::INTEGER,
        ]);

        return array_map(fn (array $r) => new SubstanceDTO(
            id: $r['id'],
            canonicalName: $r['canonical_name'],
            cas: $r['cas'],
            aliases: json_decode($r['aliases'], true) ?: [],
        ), $rows);
    }
}
