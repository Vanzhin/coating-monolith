<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Database;

use App\Shared\Infrastructure\Database\Migrations\Version20261106121000;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Данные-миграция {@see Version20261106121000}: приводит существующие требования к новой таксономии
 * (material/periodic вместо material+non_material+mode). Сеем строки сырым SQL прямо в
 * `compliance_requirement` (мимикрируя реальную jsonb-форму, которую пишет
 * {@see \App\Compliance\Infrastructure\Database\DBAL\RequirementItemsType} — каждая позиция несёт
 * инфраструктурный дискриминатор `type`), минуя домен — миграция намеренно декаплена от доменных классов
 * (следующая задача плана удаляет `MaterialMode`/`NonMaterialItem`).
 */
final class PeriodicItemMigrationTest extends KernelTestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conn = static::getContainer()->get(Connection::class);
    }

    public function test_instruction_without_quantity_becomes_periodic_with_one_piece(): void
    {
        $id = $this->insertRequirement('non_material', [
            $this->item('non_material', 'Инструктаж по ОТ', 'п.1'),
        ]);

        $this->runMigration();

        $row = $this->fetchRequirement($id);
        self::assertSame('periodic', $row['type']);
        $item = $this->decodeItems($row)[0];
        self::assertSame('periodic', $item['type'], 'дискриминатор позиции синхронизирован с новым классом');
        self::assertSame(1, $item['quantity']['amount']);
        self::assertSame('pcs', $item['quantity']['unit']);
    }

    public function test_instruction_item_with_existing_quantity_is_left_as_is(): void
    {
        $id = $this->insertRequirement('non_material', [
            $this->item('non_material', 'Медосмотр', 'п.4', quantity: ['amount' => 2, 'unit' => 'pcs']),
        ]);

        $this->runMigration();

        $item = $this->decodeItems($this->fetchRequirement($id))[0];
        self::assertSame(2, $item['quantity']['amount'], 'уже заданное quantity не перетирается единицей по умолчанию');
    }

    public function test_consumable_material_becomes_periodic_without_mode(): void
    {
        $id = $this->insertRequirement('material', [
            $this->item('material', 'Мыло', 'п.2', quantity: ['amount' => 1200.0, 'unit' => 'ml'], mode: 'consumable'),
        ]);

        $this->runMigration();

        $row = $this->fetchRequirement($id);
        self::assertSame('periodic', $row['type']);
        $item = $this->decodeItems($row)[0];
        self::assertArrayNotHasKey('mode', $item);
        self::assertSame('periodic', $item['type']);
        // Postgres jsonb нормализует числа (1200.0 -> 1200) при хранении — сравниваем по значению, не по типу.
        self::assertEqualsWithDelta(1200.0, $item['quantity']['amount'], 1e-9, 'quantity сохранён');
        self::assertSame('ml', $item['quantity']['unit']);
    }

    public function test_inventoried_material_stays_material_without_mode(): void
    {
        $id = $this->insertRequirement('material', [
            $this->item('material', 'Перчатки', 'п.3', quantity: ['amount' => 10.0, 'unit' => 'pair'], mode: 'inventoried'),
        ]);

        $this->runMigration();

        $row = $this->fetchRequirement($id);
        self::assertSame('material', $row['type'], 'инвентарное остаётся material');
        $item = $this->decodeItems($row)[0];
        self::assertArrayNotHasKey('mode', $item);
        self::assertSame('material', $item['type']);
    }

    /** Позиция без явного mode исторически = inventoried ({@see \App\Compliance\Domain\ValueObject\Item\MaterialItem::fromArray}). */
    public function test_material_item_without_explicit_mode_defaults_to_inventoried_and_stays_material(): void
    {
        $id = $this->insertRequirement('material', [
            $this->item('material', 'Респиратор', 'п.6', quantity: ['amount' => 1.0, 'unit' => 'pcs']),
            $this->item('material', 'Перчатки', 'п.3', quantity: ['amount' => 10.0, 'unit' => 'pair'], mode: 'inventoried'),
        ]);

        $this->runMigration();

        self::assertSame('material', $this->fetchRequirement($id)['type']);
    }

    public function test_second_run_is_noop(): void
    {
        $instructionId = $this->insertRequirement('non_material', [
            $this->item('non_material', 'Инструктаж', 'п.1'),
        ]);
        $consumableId = $this->insertRequirement('material', [
            $this->item('material', 'Мыло', 'п.2', quantity: ['amount' => 1200.0, 'unit' => 'ml'], mode: 'consumable'),
        ]);
        $inventoriedId = $this->insertRequirement('material', [
            $this->item('material', 'Перчатки', 'п.3', quantity: ['amount' => 10.0, 'unit' => 'pair'], mode: 'inventoried'),
        ]);

        $this->runMigration();
        $after1 = [
            $this->fetchRequirement($instructionId),
            $this->fetchRequirement($consumableId),
            $this->fetchRequirement($inventoriedId),
        ];

        $this->runMigration();
        $after2 = [
            $this->fetchRequirement($instructionId),
            $this->fetchRequirement($consumableId),
            $this->fetchRequirement($inventoriedId),
        ];

        self::assertSame($after1, $after2, 'повторный прогон не меняет состояние уже приведённых требований');
    }

    public function test_mixed_material_requirement_aborts_and_is_left_untouched(): void
    {
        $id = $this->insertRequirement('material', [
            $this->item('material', 'Перчатки', 'п.3', quantity: ['amount' => 10.0, 'unit' => 'pair'], mode: 'inventoried'),
            $this->item('material', 'Мыло', 'п.2', quantity: ['amount' => 1200.0, 'unit' => 'ml'], mode: 'consumable'),
        ]);
        $before = $this->fetchRequirement($id);

        try {
            $this->runMigration();
            self::fail('Ожидали AbortMigration для смешанного material-требования.');
        } catch (AbortMigration $e) {
            self::assertStringContainsString($id, $e->getMessage(), 'id смешанного требования в сообщении аборта');
        }

        self::assertSame($before, $this->fetchRequirement($id), 'смешанное требование осталось нетронутым');
    }

    private function runMigration(): void
    {
        $migration = new Version20261106121000($this->conn, new NullLogger());
        $migration->up(new Schema());
    }

    /** @param list<array<string, mixed>> $items */
    private function insertRequirement(string $type, array $items): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->conn->executeStatement(
            "INSERT INTO compliance_requirement (id, name, type, position_ids, items, version)
             VALUES (:id, :name, :type, '[]'::jsonb, :items::jsonb, 1)",
            [
                'id' => $id,
                'name' => 'Тестовое требование '.$id,
                'type' => $type,
                'items' => json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
        );

        return $id;
    }

    /**
     * Мимикрирует реальную jsonb-форму позиции, какой её пишет RequirementItemsType: инфраструктурный
     * дискриминатор `type` + доменные поля позиции (id/label/cadence/basis[/quantity][/mode]).
     *
     * @param array{amount: int|float, unit: string}|null $quantity
     *
     * @return array<string, mixed>
     */
    private function item(string $itemType, string $label, string $basis, ?array $quantity = null, ?string $mode = null): array
    {
        $item = [
            'type' => $itemType,
            'id' => Uuid::v7()->toRfc4122(),
            'label' => $label,
            'cadence' => ['kind' => 'periodic', 'number' => 1, 'unit' => 'year'],
            'basis' => $basis,
        ];
        if (null !== $quantity) {
            $item['quantity'] = $quantity;
        }
        if (null !== $mode) {
            $item['mode'] = $mode;
        }

        return $item;
    }

    /** @return array{id: string, type: string, items: string} */
    private function fetchRequirement(string $id): array
    {
        /** @var array{id: string, type: string, items: string}|false $row */
        $row = $this->conn->fetchAssociative('SELECT id, type, items FROM compliance_requirement WHERE id = :id', ['id' => $id]);
        self::assertIsArray($row, sprintf('требование %s не найдено', $id));

        return $row;
    }

    /**
     * @param array{id: string, type: string, items: string} $row
     *
     * @return list<array<string, mixed>>
     */
    private function decodeItems(array $row): array
    {
        return json_decode($row['items'], true, 512, JSON_THROW_ON_ERROR);
    }
}
