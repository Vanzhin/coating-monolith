<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Cache;

use App\Coatings\Domain\Compliance\Compliance;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Проекция-снапшот соответствий систем стандартам (read-model). Пишется проектором при мутации
 * системы, читается показом (карточки/модалка) и поиском (фасеты). Doctrine ORM таблицу не мапит —
 * доступ только через этот тонкий репозиторий. Колонки category/durability — обобщённые слоты под
 * две оси маркировки: primary→category, secondary→durability.
 */
final class CoatingSystemComplianceCacheRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @param list<Compliance> $compliance
     */
    public function rewrite(string $systemId, array $compliance): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $systemId],
        );
        foreach ($compliance as $c) {
            $this->conn->executeStatement(
                'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                 VALUES (:id, :std, :cat, :dur)',
                [
                    'id' => $systemId,
                    'std' => $c->standard->value,
                    'cat' => $c->primary,
                    'dur' => $c->secondary,
                ],
            );
        }
    }

    /**
     * @return list<Compliance>
     */
    public function findBySystem(string $systemId): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT standard, category, durability FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $systemId],
        );

        return array_map(static fn (array $r): Compliance => self::hydrate($r), $rows);
    }

    /**
     * @param list<string> $systemIds
     *
     * @return array<string, list<Compliance>>
     */
    public function findBySystemIds(array $systemIds): array
    {
        if ([] === $systemIds) {
            return [];
        }

        $rows = $this->conn->fetchAllAssociative(
            'SELECT system_id, standard, category, durability FROM coating_system_compliance WHERE system_id IN (:ids)',
            ['ids' => $systemIds],
            ['ids' => ArrayParameterType::STRING],
        );

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['system_id']][] = self::hydrate($r);
        }

        return $map;
    }

    public function delete(string $systemId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $systemId],
        );
    }

    /**
     * @param array{standard: string, category: string, durability: string} $row
     */
    private static function hydrate(array $row): Compliance
    {
        return Compliance::fromArray([
            'standard' => $row['standard'],
            'primary' => $row['category'],
            'secondary' => $row['durability'],
        ]);
    }
}
