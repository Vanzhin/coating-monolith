<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Compliance: бэкфилл стабильного id в позиции требований (jsonb compliance_requirement.items) для записей,
 * заведённых до появления id. Идемпотентно: позиции с уже проставленным id не трогаем. Прод — 3 требования.
 */
final class Version20261020120500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Compliance: бэкфилл id в позиции существующих требований (jsonb).';
    }

    public function up(Schema $schema): void
    {
        /** @var list<array{id: string, items: string}> $rows */
        $rows = $this->connection->fetchAllAssociative('SELECT id, items FROM compliance_requirement');
        foreach ($rows as $row) {
            $items = json_decode((string) $row['items'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items)) {
                continue;
            }
            $changed = false;
            foreach ($items as &$item) {
                if (is_array($item) && '' === trim((string) ($item['id'] ?? ''))) {
                    $item = ['id' => Uuid::v7()->toRfc4122()] + $item;
                    $changed = true;
                }
            }
            unset($item);
            if ($changed) {
                $this->connection->executeStatement(
                    'UPDATE compliance_requirement SET items = :items WHERE id = :id',
                    ['items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'id' => $row['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Необратимо (id — стабильные идентификаторы); откат не предусмотрен.
    }
}
