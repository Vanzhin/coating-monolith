<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «Списать выбранные/всё» с карточки выдачи: наполнение org-scoped корзины — черновика НОВОГО агрегата
 * {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct} — held-фактами человека. Черновик один на
 * пару (требование × организация), поэтому несколько людей одной организации по одному требованию
 * собираются в ОДИН акт; другая организация — отдельный черновик. `obligationKeys === null` — «Списать всё».
 */
final class AddToWriteOffDraftTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private WriteOffActRepositoryInterface $writeOffActRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->writeOffActRepo = $c->get(WriteOffActRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_creates_draft_on_new_aggregate_with_held_fact_as_full_line(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k, 'organizationId' => $org] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // на руках 10

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();

        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act, 'черновик создан под парой требование×организация');
        self::assertTrue($act->isDraft());
        self::assertSame([$p], $act->profileIds());
        $lines = $act->lines();
        self::assertCount(1, $lines, 'held-факт позиции добавлен строкой');
        self::assertEqualsWithDelta(10.0, $lines[0]->quantity(), 1e-9, 'строка = полное количество на руках');
        self::assertSame('Перчатки', $lines[0]->labelSnapshot());
        self::assertNull($lines[0]->reason(), 'причина пустая — укажут на странице акта');
        self::assertSame($p, $lines[0]->profileId());
    }

    public function test_does_not_duplicate_already_added_fact(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k, 'organizationId' => $org] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();
        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();

        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act);
        self::assertCount(1, $act->lines(), 'факт в корзине не дублируется повторным вызовом');
    }

    public function test_two_people_of_same_organization_land_in_one_draft(): void
    {
        ['positionId' => $pos, 'requirementId' => $r, 'key' => $k] = $this->createSharedRequirement();
        ['profileId' => $p1, 'organizationId' => $org, 'departmentId' => $dept] = $this->enrollProfileForPosition($pos, $r);
        $p2 = $this->enrollSecondProfileInOrganization($org, $dept, $pos);

        $this->issueCard($p1, $r, $k);
        $this->issueCard($p2, $r, $k);

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p1, $r, [$k]));
        $this->reload();
        $this->commandBus->execute(new AddToWriteOffDraftCommand($p2, $r, [$k]));
        $this->reload();

        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act, 'один открытый черновик на пару требование×организация');
        self::assertSame([$p1, $p2], $act->profileIds(), 'оба человека в одном акте');
        self::assertCount(2, $act->lines());
    }

    public function test_person_of_different_organization_gets_separate_draft(): void
    {
        ['positionId' => $pos, 'requirementId' => $r, 'key' => $k] = $this->createSharedRequirement();
        ['profileId' => $p1, 'organizationId' => $org1] = $this->enrollProfileForPosition($pos, $r);
        ['profileId' => $p2, 'organizationId' => $org2] = $this->enrollProfileForPosition($pos, $r); // другая организация

        $this->issueCard($p1, $r, $k);
        $this->issueCard($p2, $r, $k);

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p1, $r, [$k]));
        $this->reload();
        $this->commandBus->execute(new AddToWriteOffDraftCommand($p2, $r, [$k]));
        $this->reload();

        $act1 = $this->writeOffActRepo->findOpenDraft($r, $org1);
        $act2 = $this->writeOffActRepo->findOpenDraft($r, $org2);
        self::assertNotNull($act1);
        self::assertNotNull($act2);
        self::assertNotSame($act1->getId(), $act2->getId(), 'разные организации — разные черновики');
        self::assertSame([$p1], $act1->profileIds());
        self::assertSame([$p2], $act2->profileIds());
    }

    public function test_select_all_picks_all_inventory_held_positions(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k1, 'organizationId' => $org] = $this->enrollCompliance('Перчатки', null, [
            ['label' => 'Нарукавники', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '5', 'unit' => 'pair', 'basis' => 'п.7'],
        ]);
        $k2 = $this->obligationKeyFor($r, 'Нарукавники');
        $this->issueCard($p, $r, $k1); // на руках 10
        $this->issueCard($p, $r, $k2, '5'); // на руках 5

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, null));
        $this->reload();

        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act);
        $labels = array_map(static fn ($line): string => $line->labelSnapshot(), $act->lines());
        sort($labels);
        self::assertSame(['Нарукавники', 'Перчатки'], $labels, '«Списать всё» захватило обе инвентарные позиции');
    }

    /** @return array{positionId: string, requirementId: string, key: string} */
    private function createSharedRequirement(string $itemLabel = 'Перчатки'): array
    {
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $pos = $bus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $req = $bus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$pos->id],
            [['label' => $itemLabel, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        return ['positionId' => $pos->id, 'requirementId' => $req->id, 'key' => $this->obligationKeyFor($req->id, $itemLabel)];
    }

    /**
     * Новая организация+отдел+профиль на уже существующей должности/требовании.
     *
     * @return array{profileId: string, organizationId: string, departmentId: string}
     */
    private function enrollProfileForPosition(string $positionId, string $requirementId): array
    {
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $org = $bus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $bus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profileId = $this->enrollSecondProfileInOrganization($org->id, $dept->id, $positionId);

        return ['profileId' => $profileId, 'organizationId' => $org->id, 'departmentId' => $dept->id];
    }

    /** Ещё один профиль в УЖЕ СУЩЕСТВУЮЩЕЙ организации/отделе — для кейса «два человека одной организации». */
    private function enrollSecondProfileInOrganization(string $organizationId, string $departmentId, string $positionId): string
    {
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $profile = $bus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Петров', firstName: 'Пётр', middleName: 'Петрович',
            positionId: $positionId, organizationId: $organizationId, departmentId: $departmentId,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return $profile->id;
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
