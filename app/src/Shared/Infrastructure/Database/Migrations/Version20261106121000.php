<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: приводим существующие требования (`compliance_requirement`) к новой таксономии —
 * материальное (`material`) и периодическое (`periodic`) вместо материального+нематериального+режима.
 * «Выдача» (`material`) полиморфна: держит и инвентарь, и расходник в одной карточке — смешанное
 * material-требование это норма, не аномалия; тип меняется ПОЗИЦИОННО, а не у требования целиком.
 *
 * - `non_material` → требование `periodic`: позициям без `quantity` проставляем `{amount:1,unit:'pcs'}`
 *   (инструктаж = 1 раз), позиция становится `periodic`.
 * - `material` → требование ОСТАЁТСЯ `material`; у каждой позиции смотрим `mode`: `consumable` → позиция
 *   становится `periodic` (расходник/ДСИЗ, quantity сохраняется), иначе (`inventoried` или `mode` вовсе
 *   не задан — исторический дефолт инвентаря) → позиция остаётся `material`. `mode` в обоих случаях убираем.
 * - `periodic` — уже приведено, не трогаем.
 *
 * Дискриминатор `type` внутри каждой позиции (инфраструктурная метка класса — {@see
 * \App\Compliance\Infrastructure\Database\DBAL\RequirementItemsType}, не часть доменной сериализации позиции)
 * синхронизируем с новым классом позиции — иначе при гидратации Doctrine соберёт позицию старого класса, и
 * свежепроставленное `quantity` потеряется.
 *
 * Идемпотентно: `non_material` строки мутируются один раз (type требования уже `periodic` — не находим).
 * `material` строка, где НИ у одной позиции нет ключа `mode` — уже приведена (mode убран ранее), no-op.
 * Намеренно читаем/пишем jsonb сырыми массивами (json_decode/json_encode), без доменных классов — по
 * шаблону {@see Version20261020120500}: `MaterialMode`/`NonMaterialItem` удалены, миграция от них не зависит.
 *
 * `down()` — no-op: откат смешал бы обратно `periodic`-позиции разного происхождения (были `non_material`
 * или `material`-consumable) без возможности различить, откуда какая, — необратимо.
 */
final class Version20261106121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: требования non_material → periodic; material — тип позиций по mode (consumable→periodic), требование остаётся material.';
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: string, type: string, items: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, type, items FROM compliance_requirement');

        foreach ($rows as $row) {
            $items = json_decode((string) $row['items'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items)) {
                continue;
            }

            $type = (string) $row['type'];

            if ('non_material' === $type) {
                $newType = 'periodic';
                foreach ($items as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $item['type'] = 'periodic';
                    if (!array_key_exists('quantity', $item)) {
                        $item['quantity'] = ['amount' => 1, 'unit' => 'pcs'];
                    }
                }
                unset($item);
            } elseif ('material' === $type) {
                $hasModeKey = array_any($items, static fn ($item): bool => is_array($item) && array_key_exists('mode', $item));
                if (!$hasModeKey) {
                    continue; // уже приведено (mode убран ранее) — идемпотентный no-op
                }

                $newType = 'material'; // требование остаётся «Выдачей» — меняется только тип позиций
                foreach ($items as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $isConsumable = 'consumable' === ($item['mode'] ?? 'inventoried');
                    unset($item['mode']);
                    $item['type'] = $isConsumable ? 'periodic' : 'material';
                }
                unset($item);
            } else {
                continue; // periodic (или неизвестный) — уже приведено, не трогаем
            }

            $this->connection->executeStatement(
                'UPDATE compliance_requirement SET type = :type, items = :items WHERE id = :id',
                [
                    'type' => $newType,
                    'items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'id' => $row['id'],
                ],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Необратимо: periodic-позиции произошли либо от non_material, либо от material-consumable —
        // после мутации это неразличимо, откат к старой таксономии невозможен без потери информации.
    }
}
