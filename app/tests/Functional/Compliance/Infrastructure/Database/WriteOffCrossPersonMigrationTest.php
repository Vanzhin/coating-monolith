<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Database;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
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
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Перенос старых per-person актов списания (`compliance_write_off_act`/`_item`) в кросс-персон
 * {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct} командой
 * `app:compliance:migrate-writeoff-crossperson`. Сеем реальный учёт+выдачу через {@see EnrollsComplianceTrait}
 * (чтобы существовали profile_compliance, факт выдачи и организация профиля), затем сырым SQL кладём старый акт
 * в per-person таблицы и проверяем результат команды: организация+наименование резолвятся доменом/квери,
 * черновики одной (требование×организация) сливаются, повтор не плодит дублей, held НЕ гасится повторно.
 */
final class WriteOffCrossPersonMigrationTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private Connection $conn;
    private CommandBusInterface $commandBus;
    private ProfileComplianceRepositoryInterface $repo;
    private WriteOffActRepositoryInterface $writeOffActRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = static::getContainer();
        $this->conn = $c->get(Connection::class);
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->writeOffActRepo = $c->get(WriteOffActRepositoryInterface::class);
    }

    public function test_signed_old_act_migrates_with_org_and_label_without_requenching_held(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k, 'organizationId' => $org] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // на руках 10
        $pcId = $this->profileComplianceId($p);
        $recordId = $this->firstRecordId($p);

        $oldActId = Uuid::v7()->toRfc4122();
        $this->insertOldAct($oldActId, $pcId, $r, 'signed', actNumber: '39', signed: true);
        $this->insertOldItem($oldActId, $recordId, 3.0, 'physical_wear');

        $this->runCommand();
        $this->clear();

        $act = $this->writeOffActRepo->findById($oldActId);
        self::assertNotNull($act, 'подписанный старый акт перенесён под тем же id');
        self::assertTrue($act->isSigned());
        self::assertSame($org, $act->organizationId(), 'организация резолвлена через GetProfileQuery');
        self::assertSame('39', $act->actNumber());
        self::assertCount(1, $act->lines());
        $line = $act->lines()[0];
        self::assertSame($p, $line->profileId());
        self::assertSame($recordId, $line->recordId());
        self::assertSame('Перчатки', $line->labelSnapshot(), 'наименование из obligationLabelOf домена');
        self::assertEqualsWithDelta(3.0, $line->quantity(), 1e-9);

        // Ключевая проверка: перенос НЕ применяет гашение — held остаётся прежним (старый путь уже погасил).
        self::assertEqualsWithDelta(10.0, $this->repo->findByProfile($p)?->heldOf($k), 1e-9, 'held не погашён повторно миграцией');
    }

    public function test_rerun_creates_no_duplicates(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $pcId = $this->profileComplianceId($p);
        $recordId = $this->firstRecordId($p);

        $oldActId = Uuid::v7()->toRfc4122();
        $this->insertOldAct($oldActId, $pcId, $r, 'signed', actNumber: '39', signed: true);
        $this->insertOldItem($oldActId, $recordId, 3.0, 'physical_wear');

        $this->runCommand();
        $display = $this->runCommand()->getDisplay(); // второй прогон
        $this->clear();

        self::assertSame(1, $this->countNewActs($r), 'повторный прогон не плодит дублей');
        self::assertStringContainsString('Пропущено (уже перенесены): 1', $display);
    }

    public function test_two_drafts_same_req_org_merge_into_one_act_with_two_profiles(): void
    {
        [$pos, $org, $dept, $r, $kGloves, $kHelmet] = $this->seedSharedRequirementTwoPeople();

        ['profileId' => $p1] = $this->createProfile($pos, $org, $dept, 'Иванов');
        $p2 = $this->createProfile($pos, $org, $dept, 'Петров')['profileId'];
        $this->issueCard($p1, $r, $kGloves, '10');
        $this->issueCard($p2, $r, $kHelmet, '1');

        $pc1 = $this->profileComplianceId($p1);
        $pc2 = $this->profileComplianceId($p2);
        $rec1 = $this->firstRecordId($p1);
        $rec2 = $this->firstRecordId($p2);

        // Два старых per-person ЧЕРНОВИКА одной (требование × организация), по человеку на каждого.
        $draft1 = Uuid::v7()->toRfc4122();
        $draft2 = Uuid::v7()->toRfc4122();
        $this->insertOldAct($draft1, $pc1, $r, 'formed');
        $this->insertOldItem($draft1, $rec1, 10.0, null);
        $this->insertOldAct($draft2, $pc2, $r, 'formed');
        $this->insertOldItem($draft2, $rec2, 1.0, null);

        $this->runCommand();
        $this->clear();

        self::assertSame(1, $this->countNewActs($r), 'два черновика одной (req,org) слились в один акт');
        $targetId = min($draft1, $draft2); // детерминированный id = наименьший в группе
        $act = $this->writeOffActRepo->findById($targetId);
        self::assertNotNull($act, 'целевой id — наименьший id группы');
        self::assertTrue($act->isDraft());
        self::assertCount(2, $act->lines());
        $profiles = array_map(static fn ($l) => $l->profileId(), $act->lines());
        sort($profiles);
        $expected = [$p1, $p2];
        sort($expected);
        self::assertSame($expected, $profiles, 'строки двух разных профилей');
    }

    private function insertOldAct(string $id, string $pcId, string $requirementId, string $status, ?string $actNumber = null, bool $signed = false): void
    {
        $commission = $signed ? json_encode(['members' => [['organization' => '', 'position' => 'рук. ОТиПБ', 'fio' => 'Алиханова Н.И.', 'date' => '']]], JSON_THROW_ON_ERROR) : null;
        $this->conn->executeStatement(
            'INSERT INTO compliance_write_off_act
                (id, profile_compliance_id, requirement_id, status, commission, act_number, act_date, scan_file_id, signed_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, ?, ?)',
            [
                $id, $pcId, $requirementId, $status, $commission, $actNumber,
                $signed ? '2026-08-10 00:00:00' : null,
                $signed ? 'scan-'.substr($id, 0, 8) : null,
                $signed ? '2026-08-10 12:00:00' : null,
                '2026-08-01 10:00:00', '2026-08-01 10:00:00',
            ],
        );
    }

    private function insertOldItem(string $oldActId, string $recordId, float $quantity, ?string $reason): void
    {
        $this->conn->executeStatement(
            'INSERT INTO compliance_write_off_item (id, write_off_act_id, record_id, quantity, reason) VALUES (?, ?, ?, ?, ?)',
            [Uuid::v7()->toRfc4122(), $oldActId, $recordId, $quantity, $reason],
        );
    }

    private function countNewActs(string $requirementId): int
    {
        return (int) $this->conn->fetchOne('SELECT count(*) FROM compliance_writeoff_act WHERE requirement_id = ?', [$requirementId]);
    }

    private function profileComplianceId(string $profileId): string
    {
        return (string) $this->conn->fetchOne('SELECT id FROM compliance_profile_compliance WHERE profile_id = ?', [$profileId]);
    }

    private function firstRecordId(string $profileId): string
    {
        $this->clear();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);

        return $pc->getRecords()[0]->getId();
    }

    /** @return array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string} [posId, orgId, deptId, reqId, kGloves, kHelmet] */
    private function seedSharedRequirementTwoPeople(): array
    {
        $pos = $this->commandBus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $this->commandBus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $this->commandBus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $req = $this->commandBus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$pos->id],
            [
                ['label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5'],
                ['label' => 'Каска защитная', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pair', 'basis' => 'п.9'],
            ],
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        return [$pos->id, $org->id, $dept->id, $req->id, $this->obligationKeyFor($req->id, 'Перчатки'), $this->obligationKeyFor($req->id, 'Каска защитная')];
    }

    /** @return array{profileId: string} */
    private function createProfile(string $positionId, string $organizationId, string $departmentId, string $lastName): array
    {
        $profile = $this->commandBus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: $lastName, firstName: 'Имя', middleName: 'Отчество',
            positionId: $positionId, organizationId: $organizationId, departmentId: $departmentId,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return ['profileId' => $profile->id];
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:compliance:migrate-writeoff-crossperson'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    private function clear(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
