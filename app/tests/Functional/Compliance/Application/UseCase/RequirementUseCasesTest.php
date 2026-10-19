<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Query\GetRequirement\GetRequirementQuery;
use App\Compliance\Application\UseCase\Query\GetRequirement\GetRequirementQueryResult;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\DeletePosition\DeletePositionCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequirementUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private RequirementRepositoryInterface $repo;
    /** @var list<string> */
    private array $createdIds = [];
    /** @var list<string> */
    private array $createdPositionIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->repo = $c->get(RequirementRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->authenticateAsSystem();
        foreach ($this->createdIds as $id) {
            if (null !== ($r = $this->repo->findOneById($id))) {
                $this->repo->remove($r);
            }
        }
        foreach ($this->createdPositionIds as $id) {
            try {
                $this->commandBus->execute(new DeletePositionCommand($id));
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    private function createPosition(string $title): string
    {
        $result = $this->commandBus->execute(new CreatePositionCommand($title.' '.uniqid('', true)));
        \assert($result instanceof CreatePositionCommandResult);
        $this->createdPositionIds[] = $result->id;

        return $result->id;
    }

    /**
     * @param list<string>               $positionIds
     * @param list<array<string, mixed>> $items
     */
    private function save(?string $id, string $name, string $type, array $positionIds, array $items, ?string $journalKind = null): string
    {
        $result = $this->commandBus->execute(new SaveRequirementCommand($id, $name, $type, $positionIds, $items, journalKind: $journalKind));
        \assert($result instanceof SaveRequirementCommandResult);
        $this->createdIds[] = $result->id;

        return $result->id;
    }

    /** @return array<string, mixed> */
    private function materialItem(string $label = 'Перчатки', string $basis = 'п.5 норм'): array
    {
        return ['label' => $label, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => $basis];
    }

    /** @return array<string, mixed> */
    private function periodicItem(string $label = 'Противопожарный инструктаж', string $basis = 'ГОСТ 12.0.004'): array
    {
        return ['label' => $label, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pcs', 'basis' => $basis];
    }

    public function test_save_and_read_material_requirement(): void
    {
        $posId = $this->createPosition('Маляр');
        $id = $this->save(null, 'Личная карточка учёта выдачи СИЗ', 'material', [$posId], [$this->materialItem()]);

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertSame('Личная карточка учёта выдачи СИЗ', $result->requirement->name);
        self::assertSame('material', $result->requirement->type);
        self::assertCount(1, $result->requirement->positions);
        self::assertSame($posId, $result->requirement->positions[0]->id);
        self::assertCount(1, $result->requirement->items);
        self::assertSame('Перчатки', $result->requirement->items[0]->label);
        self::assertSame('10 пара', $result->requirement->items[0]->quantityLabel);
    }

    public function test_persists_and_hydrates_typed_polymorphic_items(): void
    {
        $posId = $this->createPosition('Пескоструйщик');
        $id = $this->save(null, 'СИЗ основные', 'material', [$posId], [$this->materialItem('Каска')]);

        // Полный round-trip: сбросить identity map и перечитать из БД — DBAL-тип должен собрать типизированные позиции.
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $requirement = $this->repo->findOneById($id);
        self::assertNotNull($requirement);
        self::assertSame(ComplianceType::Material, $requirement->getType());
        $items = $requirement->getItems();
        self::assertCount(1, $items);
        self::assertInstanceOf(MaterialItem::class, $items[0]);
        self::assertSame(10.0, $items[0]->quantity()->amount);
    }

    public function test_material_requirement_holds_mixed_inventory_and_consumable_items(): void
    {
        $posId = $this->createPosition('Маляр СИЗ');
        $id = $this->save(null, 'Личная карточка учёта выдачи СИЗ', 'material', [$posId], [
            ['type' => 'material'] + $this->materialItem('Каска'),
            ['type' => 'periodic', 'amount' => '1200', 'unit' => 'ml'] + $this->periodicItem('Мыло жидкое', 'п.9 Типовых норм'),
        ]);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $requirement = $this->repo->findOneById($id);
        self::assertNotNull($requirement);
        self::assertSame(ComplianceType::Material, $requirement->getType(), 'требование остаётся «Выдачей»');
        $items = $requirement->getItems();
        self::assertCount(2, $items);
        self::assertInstanceOf(MaterialItem::class, $items[0]);
        self::assertInstanceOf(PeriodicItem::class, $items[1]);

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertSame('material', $result->requirement->items[0]->type);
        self::assertSame('periodic', $result->requirement->items[1]->type);
    }

    public function test_empty_basis_rejected(): void
    {
        $posId = $this->createPosition('Сварщик');
        $this->expectException(AppException::class);
        $this->save(null, 'СИЗ', 'material', [$posId], [$this->materialItem(basis: '')]);
    }

    public function test_position_may_belong_to_multiple_requirements(): void
    {
        $posId = $this->createPosition('Кровельщик');
        $this->save(null, 'СИЗ летние', 'material', [$posId], [$this->materialItem()]);
        // Без ограничения уникальности — та же должность в другом требовании допустима.
        $id = $this->save(null, 'СИЗ зимние', 'material', [$posId], [$this->materialItem('Ватник')]);

        self::assertNotEmpty($id);
    }

    public function test_update_merges_items_and_soft_removes_absent(): void
    {
        // Сохранение формы без id исходной позиции — она пропадает из состава и помечается removed
        // (мягкое удаление, см. Requirement::mergeItems), а не исчезает: getItems() отдаёт все 3,
        // но форма (DTO через activeItems()) показывает только 2 активные.
        $posId = $this->createPosition('Электрик');
        $id = $this->save(null, 'СИЗ', 'material', [$posId], [$this->materialItem()]);
        $this->save($id, 'СИЗ', 'material', [$posId], [$this->materialItem('Каска'), $this->materialItem('Ботинки')]);

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertCount(2, $result->requirement->items, 'форма редактирования показывает только активные позиции');

        $requirement = $this->repo->findOneById($id);
        self::assertNotNull($requirement);
        self::assertCount(2, $requirement->activeItems());
        self::assertCount(3, $requirement->getItems(), 'getItems() отдаёт все, включая мягко удалённую — для факт-лога/резолва/списания');
    }

    public function test_type_change_on_update_rejected(): void
    {
        $posId = $this->createPosition('Монтажник');
        $id = $this->save(null, 'СИЗ', 'material', [$posId], [$this->materialItem()]);

        $this->expectException(AppException::class);
        $this->save($id, 'СИЗ', 'periodic', [$posId], [$this->periodicItem()]);
    }

    public function test_material_item_persists_stable_id_across_edit(): void
    {
        $posId = $this->createPosition('Кладовщик');
        $id = $this->save(null, 'Нормы СИЗ', 'material', [$posId], [$this->materialItem('Мыло')]);

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertCount(1, $result->requirement->items);
        $originalItemId = $result->requirement->items[0]->id;
        self::assertNotSame('', $originalItemId);

        // правка: наименование меняем, id в форме возвращаем — id обязан сохраниться
        $this->save($id, 'Нормы СИЗ', 'material', [$posId], [
            $this->materialItem('Мыло жидкое') + ['id' => $originalItemId],
        ]);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $reloaded = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($reloaded instanceof GetRequirementQueryResult);
        self::assertNotNull($reloaded->requirement);
        self::assertSame($originalItemId, $reloaded->requirement->items[0]->id);
        self::assertSame('Мыло жидкое', $reloaded->requirement->items[0]->label);
    }

    public function test_save_and_read_periodic_requirement(): void
    {
        $posId = $this->createPosition('Пожарный инструктор');
        $id = $this->save(null, 'Противопожарный инструктаж', 'periodic', [$posId], [$this->periodicItem()]);

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertSame('periodic', $result->requirement->type);
        self::assertCount(1, $result->requirement->items);
        self::assertSame('Противопожарный инструктаж', $result->requirement->items[0]->label);
        // Round-trip DTO: количество периодической позиции обязано попадать в форму так же, как у материальной.
        self::assertSame('1 шт.', $result->requirement->items[0]->quantityLabel);
    }

    public function test_persists_and_hydrates_periodic_item(): void
    {
        $posId = $this->createPosition('Инструктор по ОТ');
        $id = $this->save(null, 'Инструктаж по ОТ', 'periodic', [$posId], [$this->periodicItem()]);

        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $requirement = $this->repo->findOneById($id);
        self::assertNotNull($requirement);
        self::assertSame(ComplianceType::Periodic, $requirement->getType());
        $items = $requirement->getItems();
        self::assertCount(1, $items);
        self::assertInstanceOf(PeriodicItem::class, $items[0]);
        self::assertSame(1.0, $items[0]->quantity()->amount);
    }

    public function test_periodic_item_without_quantity_rejected(): void
    {
        $posId = $this->createPosition('Инструктор без количества');
        $itemWithoutQuantity = ['amount' => ''] + $this->periodicItem();
        $this->expectException(AppException::class);
        $this->save(null, 'Инструктаж без количества', 'periodic', [$posId], [$itemWithoutQuantity]);
    }

    public function test_periodic_requirement_persists_journal_kind(): void
    {
        $posId = $this->createPosition('Инструктор по ПБ');
        $id = $this->save(null, 'Журнал ПБ', 'periodic', [$posId], [$this->periodicItem()], journalKind: 'fire_safety');

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertSame('fire_safety', $result->requirement->journalKind);
    }

    public function test_material_requirement_never_persists_journal_kind(): void
    {
        $posId = $this->createPosition('Кладовщик СИЗ');
        $id = $this->save(null, 'СИЗ с журналом', 'material', [$posId], [$this->materialItem()], journalKind: 'fire_safety');

        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        \assert($result instanceof GetRequirementQueryResult);
        self::assertNotNull($result->requirement);
        self::assertNull($result->requirement->journalKind);
    }
}
