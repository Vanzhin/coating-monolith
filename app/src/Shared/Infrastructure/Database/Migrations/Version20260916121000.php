<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Конфиг трекаемых классов (FQCN + карта поле→подпись). Сид дефолта для Coating. Идемпотентно. */
final class Version20260916121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create audit_tracked_class config table and seed Coating.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audit_tracked_class (
                id UUID NOT NULL,
                entity_class VARCHAR(255) NOT NULL,
                fields JSONB NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_audit_tracked_class ON audit_tracked_class (entity_class)');
        $this->addSql("COMMENT ON COLUMN audit_tracked_class.id IS '(DC2Type:uuid)'");

        $this->addSql(<<<'SQL'
            INSERT INTO audit_tracked_class (id, entity_class, fields)
            VALUES (
                gen_random_uuid(),
                'App\Coatings\Domain\Aggregate\Coating\Coating',
                '{"title":"Название","description":"Описание","volumeSolid":"Сухой остаток, %","massDensity":"Плотность","base":"Основа","dftRange":"Толщина плёнки (DFT)","applicationMinTemp":"Мин. температура нанесения","dryingMaxTemp":"Макс. температура сушки","dryToTouch":"Высыхание на отлип","fullCure":"Полное отверждение","minRecoatingInterval":"Мин. интервал перекрытия","maxRecoatingInterval":"Макс. интервал перекрытия","pack":"Фасовка","thinner":"Разбавитель","dryHeatExposure":"Сухой нагрев","isZincRich":"Цинк-наполненное","gloss":"Глянец","isTintable":"Колеруется","recoatingInterpolationModel":"Модель интерполяции перекрытия","immersionExposure":"Иммерсия","mixingRatio":"Пропорция смешивания"}'::jsonb
            )
            ON CONFLICT (entity_class) DO NOTHING
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_tracked_class');
    }
}
