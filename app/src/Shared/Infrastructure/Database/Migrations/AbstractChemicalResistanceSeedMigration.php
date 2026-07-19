<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

abstract class AbstractChemicalResistanceSeedMigration extends AbstractMigration
{
    abstract protected function seedFileName(): string;

    public function up(Schema $schema): void
    {
        $path = __DIR__.'/../../../../ChemicalResistance/Infrastructure/Database/Seed/'.$this->seedFileName();
        if (!is_readable($path)) {
            throw new \RuntimeException("Seed file not found: $path");
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        // 1. Fetch coating id — must exist before seeding.
        $coating = $this->connection->fetchAssociative(
            'SELECT id FROM coatings_coating WHERE title = ?',
            [$data['coating_title']],
        );
        if (false === $coating) {
            throw new \RuntimeException("Coating «{$data['coating_title']}» must exist before seeding.");
        }
        $coatingId = $coating['id'];

        // 2. Batch mode: suppress per-row FTS trigger for this transaction.
        $this->connection->executeStatement("SET LOCAL chemical_resistance.suppress_search_recalc = 'on'");

        // 3. Notes — insert fresh rows; build label→id map for assessment refs.
        $labelToId = [];
        foreach ($data['notes'] as $n) {
            $noteId = $this->uuidV4();
            $this->connection->executeStatement(
                'INSERT INTO chemical_resistance_note (id, title, description) VALUES (?, ?, ?)',
                [$noteId, $n['title'], $n['description']],
            );
            $labelToId[$n['placeholder_label']] = $noteId;
        }

        // 4. Substances — upsert by canonical_name_key (unique constraint).
        //    Fallback: if canonical_name_key not found but CAS matches, reuse that row (same chemical, variant name).
        //    If neither matches: insert fresh row.
        $substanceByCanonical = [];
        foreach ($data['substances'] as $s) {
            $key = SubstanceNameNormalizer::normalize($s['canonical']);
            $existing = $this->connection->fetchAssociative(
                'SELECT id, aliases FROM chemical_resistance_substance WHERE canonical_name_key = ?',
                [$key],
            );
            // Fallback: same CAS, different canonical key (e.g. language/case variant).
            if (false === $existing && !empty($s['cas'])) {
                $existing = $this->connection->fetchAssociative(
                    'SELECT id, aliases FROM chemical_resistance_substance WHERE cas = ?',
                    [$s['cas']],
                );
            }
            if (false !== $existing) {
                $existingAliases = json_decode($existing['aliases'], true) ?: [];
                $merged = array_values(array_unique(array_merge($existingAliases, $s['aliases'] ?? [])));
                $this->connection->executeStatement(
                    'UPDATE chemical_resistance_substance SET aliases = ? WHERE id = ?',
                    [json_encode($merged, JSON_UNESCAPED_UNICODE), $existing['id']],
                );
                $substanceByCanonical[$s['canonical']] = $existing['id'];
            } else {
                $id = $this->uuidV4();
                $this->connection->executeStatement(
                    'INSERT INTO chemical_resistance_substance (id, canonical_name, canonical_name_key, cas, aliases)
                     VALUES (?, ?, ?, ?, ?)',
                    [
                        $id,
                        $s['canonical'],
                        $key,
                        $s['cas'] ?? null,
                        json_encode($s['aliases'] ?? [], JSON_UNESCAPED_UNICODE),
                    ],
                );
                $substanceByCanonical[$s['canonical']] = $id;
            }
        }

        // 5. Assessments — upsert per (coating_id, substance_id).
        $seenGradeBySubstance = [];   // Track grades per substance to detect conflicts.
        foreach ($data['assessments'] as $a) {
            $substanceId = $substanceByCanonical[$a['substance']]
                ?? throw new \RuntimeException("Substance ref «{$a['substance']}» not resolved in {$this->seedFileName()}.");

            // Warn if the same substance appears with conflicting grades.
            if (isset($seenGradeBySubstance[$substanceId]) && $seenGradeBySubstance[$substanceId] !== $a['grade']) {
                $this->write(sprintf(
                    '<comment>Grade conflict for «%s»: %s vs %s — keeping last (%s)</comment>',
                    $a['substance'], $seenGradeBySubstance[$substanceId], $a['grade'], $a['grade']
                ));
            }
            $seenGradeBySubstance[$substanceId] = $a['grade'];

            $noteIds = array_map(
                function (string $label) use ($labelToId): string {
                    return $labelToId[$label]
                        ?? throw new \RuntimeException("Note ref «$label» not resolved in {$this->seedFileName()}.");
                },
                $a['notes'] ?? [],
            );

            $this->connection->executeStatement(
                'INSERT INTO chemical_resistance_assessment
                   (id, coating_id, substance_id, grade, max_temperature_celsius, note_ids)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (coating_id, substance_id) DO UPDATE
                   SET grade                  = EXCLUDED.grade,
                       max_temperature_celsius = EXCLUDED.max_temperature_celsius,
                       note_ids               = EXCLUDED.note_ids',
                [
                    $this->uuidV4(),
                    $coatingId,
                    $substanceId,
                    $a['grade'],
                    $a['max_temperature'] ?? 40,
                    json_encode($noteIds, JSON_UNESCAPED_UNICODE),
                ],
            );
        }

        // 6. Single batched FTS rebuild for this coating after all inserts.
        $this->connection->executeStatement(
            'SELECT coatings_coating_search_rebuild(?)',
            [$coatingId],
        );
    }

    public function down(Schema $schema): void
    {
        $data = json_decode(
            file_get_contents(__DIR__.'/../../../../ChemicalResistance/Infrastructure/Database/Seed/'.$this->seedFileName()),
            true,
        );

        // Fetch coating id.
        $coatingId = $this->connection->fetchOne(
            'SELECT id FROM coatings_coating WHERE title = ?',
            [$data['coating_title']],
        );
        if (!$coatingId) {
            return;
        }

        // Collect note UUIDs referenced by THIS coating's assessments before deleting them.
        $noteIdsRows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT jsonb_array_elements_text(note_ids) AS id
             FROM chemical_resistance_assessment
             WHERE coating_id = ?',
            [$coatingId],
        );
        $noteIds = array_column($noteIdsRows, 'id');

        // Remove this coating's assessments. Substances are left untouched (may be shared).
        $this->connection->executeStatement(
            'DELETE FROM chemical_resistance_assessment WHERE coating_id = ?',
            [$coatingId],
        );

        // Remove only notes that are no longer referenced by any surviving assessment.
        if (!empty($noteIds)) {
            $placeholders = implode(',', array_fill(0, count($noteIds), '?'));
            $this->connection->executeStatement(
                "DELETE FROM chemical_resistance_note
                 WHERE id::text IN ($placeholders)
                 AND NOT EXISTS (
                     SELECT 1 FROM chemical_resistance_assessment a
                     WHERE a.note_ids @> ('[\"'||chemical_resistance_note.id::text||'\"]')::jsonb
                 )",
                $noteIds,
            );
        }

        // Rebuild search vector for this coating (assessments gone, need fresh vector).
        $this->connection->executeStatement(
            'SELECT coatings_coating_search_rebuild(?)',
            [$coatingId],
        );
    }

    private function uuidV4(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
