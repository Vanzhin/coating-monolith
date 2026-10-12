<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Химстойкость: консолидация молочной кислоты. На проде вещество было размазано на 4 позиции
 * («Молочная кислота», «Lactic acid <20%», «Milk acid», «Milk acid <20%»), а у базовой из-за
 * противоречия в docx (одно соединение, CAS 50-21-5, получило и R, и NR) один вердикт терялся
 * на UNIQUE (coating, substance). Сводим к двум чистым веществам: «Молочная кислота» (полная,
 * NR в Классик / R в Стандарт) и «Молочная кислота (<20%)» (R). Идемпотентно и безопасно на
 * уже-консолидированной свежей БД (новый seed). См. docs/plans/chem-resistance-lactic-acid-consolidation.md.
 */
final class Version20261017120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Химстойкость: консолидация молочной кислоты (разъединение склеенных синонимов, фикс конфликта R/NR).';
    }

    public function up(Schema $schema): void
    {
        $c = $this->connection;

        // Служебный триггер пересчёта FTS подавляем — пересоберём точечно в конце.
        $c->executeStatement("SET LOCAL chemical_resistance.suppress_search_recalc = 'on'");

        $baseKey = SubstanceNameNormalizer::normalize('Молочная кислота');
        $diluteKey = SubstanceNameNormalizer::normalize('Молочная кислота (<20%)');
        $lacticDiluteKey = SubstanceNameNormalizer::normalize('Lactic acid <20%');
        $milkKey = SubstanceNameNormalizer::normalize('Milk acid');
        $milkDiluteKey = SubstanceNameNormalizer::normalize('Milk acid <20%');

        // 1. Прод-путь: переименовать вещество «Lactic acid <20%» в русский канон (id и его
        //    R-оценки сохраняются — ссылки идут по substance_id). На свежей БД строки с этим
        //    ключом нет, UPDATE затронет 0 строк (ключи взаимоисключающие — конфликта uq нет).
        $c->executeStatement(
            "UPDATE chemical_resistance_substance
                SET canonical_name = 'Молочная кислота (<20%)', canonical_name_key = ?, cas = NULL
              WHERE canonical_name_key = ?",
            [$diluteKey, $lacticDiluteKey],
        );

        // 2. Алиасы базы и варианта — объединением (без потери уже накопленных написаний).
        $this->mergeAliases($baseKey, ['2-Hydroxypropanoic acid', 'Lactic acid', 'Milk acid', 'Кислота молочная', 'Милк кислота']);
        $this->mergeAliases($diluteKey, ['Lactic acid <20%', 'Milk acid <20%', 'Молочная кислота <20%', 'Милк кислота <20%']);

        // 3. Удалить вещества-дубли «Milk acid»/«Milk acid <20%» вместе с их оценками
        //    (их вердикты уже несут база и вариант). Сначала оценки — из-за FK.
        $strayIds = $c->fetchFirstColumn(
            'SELECT id FROM chemical_resistance_substance WHERE canonical_name_key IN (?, ?)',
            [$milkKey, $milkDiluteKey],
        );
        if ([] !== $strayIds) {
            $ph = implode(',', array_fill(0, count($strayIds), '?'));
            $c->executeStatement("DELETE FROM chemical_resistance_assessment WHERE substance_id IN ($ph)", $strayIds);
            $c->executeStatement("DELETE FROM chemical_resistance_substance WHERE id IN ($ph)", $strayIds);
        }

        // 4. Классик: вердикт базы -> NR (разрешение противоречия docx; на проде, как правило,
        //    уже NR из-за keeping-last — выставляем явно, чтобы не зависеть от порядка).
        //    Матч по title регистронезависимо: покрытие на проде переименовано в «ЛИТАТАНК Классик».
        $classicId = $c->fetchOne('SELECT id FROM coatings_coating WHERE LOWER(title) = LOWER(?)', ['Литатанк Классик']);
        $baseId = $c->fetchOne('SELECT id FROM chemical_resistance_substance WHERE canonical_name_key = ?', [$baseKey]);
        if (false !== $classicId && false !== $baseId) {
            $c->executeStatement(
                'UPDATE chemical_resistance_assessment SET grade = ? WHERE coating_id = ? AND substance_id = ?',
                ['NR', $classicId, $baseId],
            );
        }

        // 5. Пересобрать FTS для всех покрытий, ссылающихся на базу или вариант (изменились
        //    канон/алиасы/вердикт — вектор поиска должен обновиться).
        $diluteId = $c->fetchOne('SELECT id FROM chemical_resistance_substance WHERE canonical_name_key = ?', [$diluteKey]);
        $ids = array_values(array_filter([$baseId, $diluteId], static fn ($v) => false !== $v));
        if ([] !== $ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $coatingIds = $c->fetchFirstColumn(
                "SELECT DISTINCT coating_id FROM chemical_resistance_assessment WHERE substance_id IN ($ph)",
                $ids,
            );
            foreach ($coatingIds as $coatingId) {
                $c->executeStatement('SELECT coatings_coating_search_rebuild(?)', [$coatingId]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Одноходовая правка данных: исходное фрагментированное/противоречивое состояние
        // восстановить нельзя (ошибочные строки удалены). Откат — no-op.
    }

    /**
     * Объединяет переданные написания в aliases вещества (по ключу), не теряя существующие.
     *
     * @param list<string> $add
     */
    private function mergeAliases(string $canonicalKey, array $add): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, aliases FROM chemical_resistance_substance WHERE canonical_name_key = ?',
            [$canonicalKey],
        );
        if (false === $row) {
            return;
        }
        /** @var list<string> $existing */
        $existing = json_decode((string) $row['aliases'], true) ?: [];
        $merged = array_values(array_unique([...$existing, ...$add]));
        $this->connection->executeStatement(
            'UPDATE chemical_resistance_substance SET aliases = ? WHERE id = ?',
            [json_encode($merged, JSON_UNESCAPED_UNICODE), $row['id']],
        );
    }
}
