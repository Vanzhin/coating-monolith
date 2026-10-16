<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Rekey-деплой, бэкфилл: проекции проставляем item_id (норма — по карте lower(label)→item.id из jsonb,
 * иначе генерим), факты переключаем obligation_key с reqId|lower(label) на reqId|itemId; не сматчившиеся
 * факты (позиция переименована/убрана из нормы) оставляем на label-ключе (orphan). Идемпотентно.
 */
final class Version20261020120900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rekey label→itemId: бэкфилл item_id проекции + перекладка obligation_key фактов';
    }

    public function up(Schema $schema): void
    {
        // 1) Карта reqId => [lower(label) => itemId] из jsonb нормы (id забэкфилен Version20261020120500).
        $map = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, items FROM compliance_requirement') as $row) {
            $items = json_decode((string) $row['items'], true) ?: [];
            foreach ($items as $item) {
                if (isset($item['id'], $item['label'])) {
                    $map[(string) $row['id']][mb_strtolower(trim((string) $item['label']))] = (string) $item['id'];
                }
            }
        }

        // 2) item_id для строк проекции: норма по карте; иначе (персональные/непонятные) — сгенерить.
        foreach ($this->connection->fetchAllAssociative('SELECT id, requirement_id, label, item_id FROM compliance_tracked_obligation') as $ob) {
            if (null !== $ob['item_id'] && '' !== $ob['item_id']) {
                continue; // идемпотентность
            }
            $label = mb_strtolower(trim((string) $ob['label']));
            $itemId = $map[(string) $ob['requirement_id']][$label] ?? Uuid::v7()->toRfc4122();
            $this->connection->executeStatement(
                'UPDATE compliance_tracked_obligation SET item_id = :iid WHERE id = :id',
                ['iid' => $itemId, 'id' => $ob['id']],
            );
        }

        // 3) Перекладка ключей фактов. Для каждого факта: reqId|lower(label) → reqId|itemId.
        //    Матч: сперва норма по карте; иначе персональная обязанность того же профиля по label; иначе оставить.
        foreach ($this->connection->fetchAllAssociative('SELECT id, profile_compliance_id, obligation_key FROM compliance_fulfillment_record') as $f) {
            $key = (string) $f['obligation_key'];
            $sep = strpos($key, '|');
            if (false === $sep) {
                continue;
            }
            $reqId = substr($key, 0, $sep);
            $labelPart = substr($key, $sep + 1); // уже lower(trim) из keyOf
            // уже перекладывали? (суффикс совпадает с каким-то itemId этого требования) — пропустить
            if (in_array($labelPart, array_values($map[$reqId] ?? []), true)) {
                continue;
            }
            $itemId = $map[$reqId][$labelPart] ?? null;
            if (null === $itemId) {
                $itemId = $this->connection->fetchOne(
                    "SELECT item_id FROM compliance_tracked_obligation WHERE profile_compliance_id = :pc AND requirement_id = :req AND origin = 'personal' AND lower(trim(label)) = :lbl",
                    ['pc' => $f['profile_compliance_id'], 'req' => $reqId, 'lbl' => $labelPart],
                ) ?: null;
            }
            if (null === $itemId) {
                continue; // orphan: позиция переименована/убрана — оставляем label-ключ
            }
            $this->connection->executeStatement(
                'UPDATE compliance_fulfillment_record SET obligation_key = :nk WHERE id = :id',
                ['nk' => $reqId.'|'.$itemId, 'id' => $f['id']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // no-op: обратный маппинг itemId→label недетерминирован.
    }
}
