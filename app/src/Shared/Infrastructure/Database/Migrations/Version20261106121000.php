<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compliance: приводим существующие требования (`compliance_requirement`) к новой таксономии —
 * материальное (`material`) и периодическое (`periodic`) вместо материального+нематериального+режима.
 *
 * - `non_material` → `periodic`: позициям без `quantity` проставляем `{amount:1,unit:'pcs'}` (инструктаж = 1 раз).
 * - `material`, у позиций ВСЕ эффективные режимы `consumable` → `periodic`, у позиций убираем `mode`.
 * - `material`, у позиций ВСЕ эффективные режимы `inventoried` → остаётся `material`, у позиций убираем `mode`.
 * - `material` со смесью `inventoried`+`consumable` в одном требовании не приводится мономорфно — требование
 *   НЕ мутируем, id собираем и после прохода по всем строкам абортим миграцию (на проде ожидаем 0 таких строк;
 *   если есть — разводить руками до деплоя).
 *
 * Дискриминатор `type` внутри каждой позиции (инфраструктурная метка класса — {@see
 * \App\Compliance\Infrastructure\Database\DBAL\RequirementItemsType}, не часть доменной сериализации позиции)
 * синхронизируем с новым классом позиции — иначе при гидратации Doctrine соберёт позицию старого класса
 * (например, `NonMaterialItem` при `requirement.type = periodic`), и свежепроставленное `quantity` потеряется.
 *
 * Идемпотентно: `non_material`/`material`-с-`mode` строки мутируются один раз, повторный прогон их не находит
 * (type уже `periodic`, либо у позиций уже нет `mode`) — no-op. Намеренно читаем/пишем jsonb сырыми
 * массивами (json_decode/json_encode), без доменных классов — по шаблону {@see Version20261020120500}:
 * `MaterialMode`/`NonMaterialItem` будут удалены следующей задачей плана, миграция не должна от них зависеть.
 *
 * `down()` — no-op: откат смешал бы обратно `periodic`-позиции разного происхождения (были `non_material`
 * или `material`-consumable) без возможности различить, откуда какая, — необратимо.
 */
final class Version20261106121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: требования non_material/material(consumable) → periodic, mode убран, пред-проверка смешанных.';
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: string, type: string, items: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, type, items FROM compliance_requirement');

        $mixedIds = [];

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
                $hasModeKey = false;
                $effectiveModes = [];
                foreach ($items as $item) {
                    if (is_array($item) && array_key_exists('mode', $item)) {
                        $hasModeKey = true;
                    }
                    $effectiveModes[] = is_array($item) ? (string) ($item['mode'] ?? 'inventoried') : 'inventoried';
                }

                if (!$hasModeKey) {
                    continue; // уже приведено (mode убран ранее) — идемпотентный no-op
                }

                $uniqueModes = array_values(array_unique($effectiveModes));
                if (\count($uniqueModes) > 1) {
                    $mixedIds[] = (string) $row['id'];
                    continue; // смешанное material-требование — не мутируем
                }

                $allConsumable = 'consumable' === $uniqueModes[0];
                $newType = $allConsumable ? 'periodic' : 'material';
                foreach ($items as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    unset($item['mode']);
                    if ($allConsumable) {
                        $item['type'] = 'periodic';
                    }
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

        $this->abortIf(
            [] !== $mixedIds,
            'Смешанные material-требования (inventoried+consumable в одном требовании) не приводятся '
            .'мономорфно к новой таксономии: '.implode(', ', $mixedIds).'. Разведите вручную перед деплоем.',
        );
    }

    public function down(Schema $schema): void
    {
        // Необратимо: periodic-позиции произошли либо от non_material, либо от material-consumable —
        // после мутации это неразличимо, откат к старой таксономии невозможен без потери информации.
    }
}
