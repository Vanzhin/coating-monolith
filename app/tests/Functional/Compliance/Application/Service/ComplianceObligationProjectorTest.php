<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\Service;

use App\Compliance\Application\Service\ComplianceObligationProjector;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Domain\ReadModel\ObligationProjection;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Cadence;
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
 * Проектор пишет read-модель `compliance_obligation_projection` из живой нормы (Requirement) + журнала фактов
 * (агрегат, Вариант B, норму не несёт). Два фокуса ревью:
 *  - held/nextDueAt/active строки проекции считаются корректно из фактов для известной нормы (periodic/1/год);
 *  - fan-out подписи списания: синхронная подпись кросс-персон акта обновляет строки проекции ВСЕХ
 *    затронутых людей одной транзакцией (write-through в {@see SignWriteOffActCommandHandler}).
 */
final class ComplianceObligationProjectorTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private ComplianceObligationProjector $projector;
    private ComplianceProjectionRebuilder $rebuilder;
    private ObligationProjectionRepositoryInterface $projectionRepo;
    private ProfileComplianceRepositoryInterface $repo;
    private WriteOffActRepositoryInterface $writeOffActRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->projector = $c->get(ComplianceObligationProjector::class);
        $this->rebuilder = $c->get(ComplianceProjectionRebuilder::class);
        $this->projectionRepo = $c->get(ObligationProjectionRepositoryInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->writeOffActRepo = $c->get(WriteOffActRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_projection_row_matches_aggregate_after_issuance(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k, '10', '2026-06-01'); // выдано 10, периодичность 1 год → срок 2027-06-01

        $this->projector->projectProfile($p);
        $this->reload();

        $row = $this->rowFor($this->projectionRepo->findForProfiles(new StringCollection($p)), $k);
        self::assertSame(10.0, $row->heldQuantity());
        self::assertEquals(new \DateTimeImmutable('2027-06-01 00:00:00'), $row->nextDueAt());
        self::assertTrue($row->isActive());
        // Структурный cadence (kind+число+единица) — та же норма, что в enrollCompliance (periodic/1/год).
        self::assertEquals(new Cadence(CadenceKind::Periodic, 1, PeriodUnit::Year), $row->cadenceStruct());
    }

    public function test_sign_writeoff_updates_projection_for_all_people_in_one_tx(): void
    {
        ['positionId' => $pos, 'requirementId' => $r, 'key' => $k] = $this->sharedGlovesRequirement();
        ['profileId' => $p1, 'organizationId' => $org, 'departmentId' => $dept] = $this->enrollProfileForPosition($pos);
        $p2 = $this->enrollSecondProfileInOrganization($org, $dept, $pos);

        $this->issueCard($p1, $r, $k, '10'); // на руках 10
        $this->issueCard($p2, $r, $k, '10'); // на руках 10

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p1, $r, [$k]));
        $this->reload();
        $this->commandBus->execute(new AddToWriteOffDraftCommand($p2, $r, [$k]));
        $this->reload();

        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act, 'оба человека одной организации попали в один кросс-персон черновик');
        $actId = $act->getId();

        $p1Record = $this->firstRecordId($p1);
        $p2Record = $this->firstRecordId($p2);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [
            ['recordId' => $p1Record, 'quantity' => 1.0, 'reason' => 'physical_wear'],
            ['recordId' => $p2Record, 'quantity' => 1.0, 'reason' => 'physical_wear'],
        ]));

        // Синхронная подпись: write-through проектора фан-аутит обоих в ОДНОЙ транзакции (async не трогаем).
        $this->commandBus->execute(new SignWriteOffActCommand(
            $actId,
            [['name' => 'Алиханова Н.И.', 'position' => 'рук. ОТиПБ']],
            '39',
            '2026-08-10',
            $this->stageComplianceScan(),
        ));
        $this->reload();

        $row1 = $this->rowFor($this->projectionRepo->findForProfiles(new StringCollection($p1)), $k);
        $row2 = $this->rowFor($this->projectionRepo->findForProfiles(new StringCollection($p2)), $k);
        self::assertSame(9.0, $row1->heldQuantity(), 'человек A: списали 1 из 10 → на руках 9 в проекции');
        self::assertSame(9.0, $row2->heldQuantity(), 'человек B обновлён тем же write-through, не только A');
    }

    /** @param list<ObligationProjection> $rows */
    private function rowFor(array $rows, string $key): ObligationProjection
    {
        foreach ($rows as $row) {
            if ($row->key() === $key) {
                return $row;
            }
        }
        self::fail(sprintf('В проекции нет строки по ключу «%s».', $key));
    }

    /** @return array{positionId: string, requirementId: string, key: string} Норма с одной материальной позицией (Перчатки), общая должность. */
    private function sharedGlovesRequirement(): array
    {
        $pos = $this->commandBus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $req = $this->commandBus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$pos->id],
            [['label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        return ['positionId' => $pos->id, 'requirementId' => $req->id, 'key' => $this->obligationKeyFor($req->id, 'Перчатки')];
    }

    /**
     * Новая организация+отдел+профиль на уже существующей должности — первый человек акта.
     *
     * @return array{profileId: string, organizationId: string, departmentId: string}
     */
    private function enrollProfileForPosition(string $positionId): array
    {
        $org = $this->commandBus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $this->commandBus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profileId = $this->enrollSecondProfileInOrganization($org->id, $dept->id, $positionId);

        return ['profileId' => $profileId, 'organizationId' => $org->id, 'departmentId' => $dept->id];
    }

    /** Ещё один профиль в УЖЕ СУЩЕСТВУЮЩЕЙ организации/отделе — второй человек акта (кросс-персон). */
    private function enrollSecondProfileInOrganization(string $organizationId, string $departmentId, string $positionId): string
    {
        $profile = $this->commandBus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Петров', firstName: 'Пётр', middleName: 'Петрович',
            positionId: $positionId, organizationId: $organizationId, departmentId: $departmentId,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);
        // Заводим учёт (агрегат + старую таблицу) через пересборщик — как остальные compliance-тесты;
        // проектор только читает агрегат, не создаёт его. Делегация в rebuild уже материализует и новую строку.
        $this->rebuilder->rebuild(profileIds: new StringCollection($profile->id));

        return $profile->id;
    }

    private function firstRecordId(string $profileId): string
    {
        $this->reload();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);

        return $pc->getRecords()[0]->getId();
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
