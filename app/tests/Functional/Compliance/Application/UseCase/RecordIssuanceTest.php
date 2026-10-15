<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
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
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Прямая запись выдачи в журнал ({@see RecordIssuanceCommand}): строки формы становятся неизменяемыми фактами
 * сразу — без черновика, топ-апа и подписи. Материальный инвариант «не меньше нормы» и полнота полей
 * инструктажа проверяются доменом (на недостаче / неполном инструктаже → AppException).
 */
final class RecordIssuanceTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $bus;
    private ProfileComplianceRepositoryInterface $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $this->bus = $c->get(CommandBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    public function test_records_material_issuance_as_journal_fact(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10 пар

        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-06-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair', 'note' => 'Jeta JP711']],
            cardNumber: 'CARD-1',
        ));
        $this->em->clear();

        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(10.0, $pc->heldOf($k), 1e-9, 'выдача сразу в held — факт пишется прямо в журнал');
        self::assertCount(1, $pc->recordsForRequirement($r), 'одна строка журнала на одну выданную позицию');
        self::assertSame('CARD-1', $pc->cardNumber(), 'первая выдача закрепила № карточки');
    }

    public function test_material_issuance_below_norm_throws(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10

        $this->expectException(AppException::class);
        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-06-01',
            [['obligationKey' => $k, 'amount' => '5', 'unit' => 'pair']], // на руках 0 + 5 < норма 10
            cardNumber: 'CARD-1',
        ));
    }

    public function test_issuance_without_card_number_throws(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10, 10 достаточно

        $this->expectException(AppException::class); // № карточки не передан и ещё не закреплён ранее
        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-06-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
        ));
    }

    public function test_instruction_rejects_missing_required_field(): void
    {
        [$p, $r, $k] = $this->setupNonMaterial();

        $this->expectException(AppException::class); // instructor_fio (обязательное) пропущено → домен отбивает запись
        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-08-05',
            [[
                'obligationKey' => $k,
                'date' => '2026-08-05',
                'instruction' => ['instruction_kind' => 'Повторный', 'instructor_doc' => 'Удостоверение № 1'],
            ]],
            cardNumber: 'CARD-1',
        ));
    }

    private const NON_MATERIAL_LABEL = 'Повторный инструктаж на рабочем месте';

    /** @return array{0: string, 1: string, 2: string} [profileId, requirementId, obligationKey] */
    private function setupNonMaterial(): array
    {
        $pos = $this->bus->execute(new CreatePositionCommand('Техинспектор '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $this->bus->execute(new CreateCounterpartyCommand('ООО Литум '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $this->bus->execute(new CreateDepartmentCommand($org->id, 'Отдел ТС '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profile = $this->bus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Ванжин', firstName: 'Николай', middleName: 'Сергеевич',
            positionId: $pos->id, organizationId: $org->id, departmentId: $dept->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: null, shoes: null, headgear: null, respirator: null, gloves: null, height: null, gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);

        $req = $this->bus->execute(new SaveRequirementCommand(
            null, 'Журнал инструктажа на рабочем месте', 'non_material', [$pos->id],
            [['label' => self::NON_MATERIAL_LABEL, 'cadenceKind' => 'periodic', 'cadenceNumber' => '6', 'cadenceUnit' => 'month', 'basis' => 'ГОСТ 12.0.004']],
            journalKind: 'workplace_ot',
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        self::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));
        $this->em->clear();

        return [$profile->id, $req->id, $this->obligationKeyFor($req->id, self::NON_MATERIAL_LABEL)];
    }
}
