<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\UseCase\Query\MatchSubstancesForSearch;

use App\ChemicalResistance\Application\DTO\SubstanceMatchDTO;
use Doctrine\DBAL\Connection;

/**
 * Матчит вещества по поисковому запросу. Использует russian-стеммер PostgreSQL,
 * чтобы словоформы (вода/воды/воду) находились так же, как FTS у coating.
 * Строка сопоставления — конкатенация canonical + cas + aliases в единый
 * to_tsvector, аналогично coating-side `chemical_resistance_suitable_substance_names`.
 * CAS дополнительно матчится exact-строкой на случай ввода полного номера.
 *
 * Семантика между словами — OR: substance матчится, если хотя бы один стем
 * запроса совпал. Мы не знаем, какие слова относятся к coating'у, а какие к
 * веществу («фенолэпоксид для воды»), поэтому не режем результат по AND —
 * пусть Twig покажет топ-3 через slice.
 */
final class MatchSubstancesForSearchQueryHandler
{
    private const FTS_LANG = 'russian';

    public function __construct(private Connection $dbal)
    {
    }

    /**
     * @return array<string, list<SubstanceMatchDTO>> — coatingId (rfc4122) → matched substances
     */
    public function __invoke(MatchSubstancesForSearchQuery $q): array
    {
        if (empty($q->coatingIds) || empty($q->searchWords)) {
            return [];
        }

        $tsquery = $this->buildPrefixTsQuery($q->searchWords);
        $rawWords = array_values(array_filter(array_map('trim', $q->searchWords), fn ($w) => '' !== $w));

        // Массивы передаём как JSON-скаляры и раскрываем через jsonb_array_elements_text —
        // один параметр можно безопасно использовать в SELECT и WHERE многократно
        // (ArrayParameterType с named-param при повторе конфликтует).
        $sql = "
            WITH coatings AS (
                SELECT jsonb_array_elements_text(:coatings::jsonb)::uuid AS id
            ),
            raw_words AS (
                SELECT jsonb_array_elements_text(:raw::jsonb) AS w
            )
            SELECT DISTINCT
                   a.coating_id::text AS cid,
                   sub.id::text        AS sid,
                   sub.canonical_name  AS canonical_name
            FROM chemical_resistance_assessment a
            JOIN chemical_resistance_substance sub ON sub.id = a.substance_id
            WHERE a.coating_id IN (SELECT id FROM coatings)
              AND chemical_resistance_is_suitable_grade(a.grade)
              AND (
                    (sub.cas IS NOT NULL AND EXISTS (SELECT 1 FROM raw_words WHERE w = sub.cas))
                    OR (
                        :tsq <> ''
                        AND to_tsvector(
                                :lang,
                                sub.canonical_name || ' ' || COALESCE(sub.cas, '') || ' ' ||
                                COALESCE(
                                    (SELECT string_agg(v, ' ') FROM jsonb_array_elements_text(sub.aliases) v),
                                    ''
                                )
                            ) @@ to_tsquery(:lang, :tsq)
                    )
              )
        ";

        $rows = $this->dbal->fetchAllAssociative(
            $sql,
            [
                'coatings' => json_encode(array_values($q->coatingIds), JSON_UNESCAPED_UNICODE),
                'raw' => json_encode(array_values($rawWords), JSON_UNESCAPED_UNICODE),
                'tsq' => $tsquery,
                'lang' => self::FTS_LANG,
            ],
        );

        /** @var array<string, list<SubstanceMatchDTO>> $out */
        $out = [];
        foreach ($rows as $r) {
            $out[$r['cid']][] = new SubstanceMatchDTO(
                substanceId: $r['sid'],
                canonicalName: $r['canonical_name'],
            );
        }

        return $out;
    }

    /**
     * «вода этанол» → «вода:* | этанол:*». Слова разбираются, санитайзятся,
     * потом объединяются через OR — substance матчится по любому из стемов.
     *
     * @param list<string> $words
     */
    private function buildPrefixTsQuery(array $words): string
    {
        $tokens = [];
        foreach ($words as $word) {
            $sanitized = preg_replace('/[&|!()<>:\'"\\\\*]/u', ' ', $word) ?? '';
            $parts = preg_split('/[\s\-.,;]+/u', $sanitized, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $tokens[] = $p.':*';
                }
            }
        }

        return implode(' | ', $tokens);
    }
}
