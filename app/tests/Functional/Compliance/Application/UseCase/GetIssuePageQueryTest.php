<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Query\GetIssuePage\GetIssuePageQuery;
use App\Compliance\Application\UseCase\Query\GetIssuePage\IssuePageDTO;
use App\Compliance\Application\UseCase\Query\GetIssuePage\IssueRowDTO;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Read-модель `/issue`: тип страницы (Finding 2) обязан читаться с КОНТЕЙНЕРА требования, не с первой строки
 * проекции («Выдача» теперь полиморфна — инвентарь+расходник в одной карточке, порядок обязанностей в проекции
 * не гарантирован). {@see \App\Compliance\Application\UseCase\Query\GetIssuePage\GetIssuePageQueryHandler}.
 */
final class GetIssuePageQueryTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->authenticateAsSystem();
    }

    /**
     * Смешанная «Выдача»: инвентарь («Перчатки») + расходник («Крем защитный», type=periodic) в ОДНОЙ норме.
     * Страница обязана отдать тип контейнера (material), а не тип первой строки проекции — какой бы она ни была.
     */
    public function test_mixed_material_requirement_page_type_is_container_type_not_first_row(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(extraItems: [
            ['label' => 'Крем защитный', 'type' => 'periodic', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1200', 'unit' => 'ml', 'basis' => 'п.9'],
        ]);

        $page = $this->issuePage($p, $r);

        self::assertSame('material', $page->requirementType, 'тип страницы — контейнер требования («Выдача»), не тип позиции расходника');
        self::assertTrue($page->requirementAllowsMixed, '«Выдача» держит инвентарь и расходник вместе');
        self::assertCount(2, $page->rows, 'обе позиции нормы присутствуют на странице');
    }

    public function test_periodic_requirement_page_type_and_mixed_flag(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->periodicRequirement();

        $page = $this->issuePage($p, $r);

        self::assertSame('periodic', $page->requirementType);
        self::assertFalse($page->requirementAllowsMixed, '«Периодическая» держит только позиции своего вида');
    }

    /**
     * Compliance soft-delete §40-43: позиция, мягко убранная из нормы требования (жива в jsonb как «надгробие»,
     * {@see \App\Compliance\Domain\ValueObject\Item\AbstractRequirementItem::isRemoved()}), но УЖЕ выданная
     * (held>0) — норм-контроль/проекция её больше не держат активной обязанностью, но держать ЕЁ оказывается
     * списываемой ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::writeOffEligibleFactsForRequirement}
     * резолвит по ВСЕМ позициям требования). Без строки на /issue её нельзя было бы выбрать для «Списать
     * выбранные»/добрать в уже открытый черновик акта — страница обязана добрать для неё строку «только списать».
     */
    public function test_removed_but_held_material_item_appears_as_writeoff_only_row(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $glovesKey] = $this->enrollCompliance('Перчатки', extraItems: [
            ['label' => 'Ботинки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '2', 'unit' => 'pair', 'basis' => 'п.5'],
        ]);
        $bootsKey = $this->obligationKeyFor($r, 'Ботинки');
        $this->issueCard($p, $r, $glovesKey);
        $this->issueCard($p, $r, $bootsKey, '2');

        // Пере-сохраняем требование, несём «Перчатки» тем же id (merge обновляет её в месте) и не несём
        // «Ботинки» вовсе — mergeItems помечает отсутствующую позицию removed (надгробие), не выкидывает.
        $this->softRemoveItemKeepingOthers($p, $r, 'Перчатки');

        $page = $this->issuePage($p, $r);

        $glovesRow = $this->rowByKey($page, $glovesKey);
        self::assertFalse($glovesRow->writeOffOnly, 'активная позиция остаётся обычной строкой выдачи');
        self::assertTrue($glovesRow->showAmountInput, 'у активной позиции поля выдачи на месте');

        $bootsRow = $this->rowByKey($page, $bootsKey);
        self::assertTrue($bootsRow->writeOffOnly, 'удалённая-но-выданная позиция — строка «только списать»');
        self::assertSame('Ботинки', $bootsRow->label, 'имя резолвится из агрегата (obligationLabelOf), не сырой ключ обязанности');
        self::assertSame('2', $bootsRow->held, 'held — из факта журнала (heldAmount)');
        self::assertFalse($bootsRow->showAmountInput, 'строка «только списать» полей выдачи не несёт');
    }

    private function issuePage(string $profileId, string $requirementId): IssuePageDTO
    {
        $result = $this->queryBus->execute(new GetIssuePageQuery($profileId, $requirementId));
        self::assertInstanceOf(IssuePageDTO::class, $result);

        return $result;
    }

    private function rowByKey(IssuePageDTO $page, string $key): IssueRowDTO
    {
        foreach ($page->rows as $row) {
            if ($row->key === $key) {
                return $row;
            }
        }

        throw new \RuntimeException(sprintf('Строка «%s» не найдена на странице выдачи.', $key));
    }

    /**
     * Пере-сохраняет требование, оставляя в форме РОВНО одну позицию ($keepLabel, с её текущим id — чтобы
     * merge обновил её на месте, а не завёл дубль с новым id) — все остальные позиции требования отсутствуют
     * среди присланных и становятся removed-надгробиями ({@see \App\Compliance\Domain\Aggregate\Requirement\Requirement::mergeItems}).
     * Должности требования переносим как есть (replacePositions на обновлении — не трогаем состав).
     */
    private function softRemoveItemKeepingOthers(string $profileId, string $requirementId, string $keepLabel): void
    {
        $requirement = static::getContainer()->get(RequirementRepositoryInterface::class)->findOneById($requirementId);
        self::assertNotNull($requirement);
        $keepId = null;
        foreach ($requirement->getItems() as $item) {
            if ($item->label() === $keepLabel) {
                $keepId = $item->id();
            }
        }
        self::assertNotNull($keepId, sprintf('Позиция «%s» не найдена в требовании.', $keepLabel));

        $result = $this->commandBus->execute(new SaveRequirementCommand(
            $requirementId, 'Личная карточка учёта выдачи СИЗ', 'material', $requirement->getPositionIds()->getList(),
            [['id' => $keepId, 'label' => $keepLabel, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($result instanceof SaveRequirementCommandResult);

        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));
    }

    /** @return array{profileId: string, requirementId: string} Периодическое требование, одна позиция-инструктаж. */
    private function periodicRequirement(): array
    {
        $bus = $this->commandBus;
        $pos = $bus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $bus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $bus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profile = $bus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Петров', firstName: 'Пётр', middleName: 'Петрович',
            positionId: $pos->id, organizationId: $org->id, departmentId: $dept->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: null, shoes: null, headgear: null, respirator: null, gloves: null, height: null, gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);

        $req = $bus->execute(new SaveRequirementCommand(
            null, 'Вводный инструктаж', 'periodic', [$pos->id],
            [['label' => 'Инструктаж', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pcs', 'basis' => 'п.9']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return ['profileId' => $profile->id, 'requirementId' => $req->id];
    }
}
