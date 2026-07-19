<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch;

use App\ChemicalResistance\Application\DTO\SubstanceMatchDTO;
use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class MatchSubstancesForSearchQueryHandler
{
    public function __construct(private Connection $dbal) {}

    /**
     * @return array<string, list<SubstanceMatchDTO>> — coatingId (rfc4122) → matched substances
     */
    public function __invoke(MatchSubstancesForSearchQuery $q): array
    {
        if (empty($q->coatingIds) || empty($q->searchWords)) {
            return [];
        }

        // Normalize search words once; discard empty results
        $normalizedWords = [];
        foreach ($q->searchWords as $word) {
            $n = SubstanceNameNormalizer::normalize($word);
            if ($n !== '') {
                $normalizedWords[] = $n;
            }
        }

        if (empty($normalizedWords)) {
            return [];
        }

        $sql = "
            SELECT a.coating_id::text AS cid,
                   sub.id::text        AS sid,
                   sub.canonical_name,
                   sub.cas,
                   sub.aliases::text   AS aliases_json
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
            WHERE a.coating_id = ANY(:coatings)
              AND chemical_resistance_is_suitable_grade(a.grade)
        ";

        $rows = $this->dbal->fetchAllAssociative(
            $sql,
            ['coatings' => $q->coatingIds],
            ['coatings' => ArrayParameterType::STRING],
        );

        /** @var array<string, list<SubstanceMatchDTO>> $out */
        $out = [];
        // Dedupe tracker: coatingId+substanceId+matchedVia
        $seen = [];

        foreach ($rows as $r) {
            $aliases     = json_decode($r['aliases_json'], true) ?: [];
            $canonNorm   = SubstanceNameNormalizer::normalize($r['canonical_name']);
            $cas         = $r['cas'];

            $matched = null;
            foreach ($normalizedWords as $needle) {
                if ($needle === $canonNorm) {
                    $matched = 'canonical';
                    break;
                }
                if ($cas !== null && $cas === $needle) {
                    $matched = 'cas';
                    break;
                }
                foreach ($aliases as $alias) {
                    if (SubstanceNameNormalizer::normalize((string) $alias) === $needle) {
                        $matched = 'alias';
                        break 2;
                    }
                }
            }

            if ($matched === null) {
                continue;
            }

            $dedupeKey = $r['cid'] . '|' . $r['sid'] . '|' . $matched;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;

            $out[$r['cid']][] = new SubstanceMatchDTO(
                substanceId:   $r['sid'],
                canonicalName: $r['canonical_name'],
                matchedVia:    $matched,
            );
        }

        return $out;
    }
}
