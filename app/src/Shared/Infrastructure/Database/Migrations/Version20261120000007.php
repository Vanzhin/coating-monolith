<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Include layer coating tags in existing coating system search documents.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE coating_system_search css
            SET search_tsvector = to_tsvector('russian', translate(concat_ws(' ',
                cs.title,
                cs.description,
                (
                    SELECT string_agg(concat_ws(' ', m.title, c.title,
                        (SELECT string_agg(t.title, ' ' ORDER BY t.title)
                         FROM coatings_coating_coating_tag ct
                         JOIN coatings_coating_tag t ON t.id = ct.tag_id
                         WHERE ct.coating_id = c.id)), ' ' ORDER BY l.position)
                    FROM coating_system_layer l
                    JOIN coatings_coating c ON c.id = l.coating_id
                    JOIN coatings_manufacturer m ON m.id = c.manufacturer_id
                    WHERE l.system_id = cs.id
                ),
                (
                    SELECT string_agg(t.title, ' ' ORDER BY t.title)
                    FROM coating_system_tag st
                    JOIN coatings_coating_tag t ON t.id = st.tag_id
                    WHERE st.coating_system_id = cs.id
                )
            ), '-.,;', '    '))
            FROM coating_system cs
            WHERE css.system_id = cs.id
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Поисковый индекс восстанавливается из текущих данных при пересборке.
    }
}
