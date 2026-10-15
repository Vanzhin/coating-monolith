<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\Service\RequirementCardProjector;
use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Domain\Templating\RepeatValue;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Прохождение не материального требования (журнал ОТ) журнальной записью ({@see RecordIssuanceCommand} →
 * `recordInstruction`): поля `InstructionDetails` захватываются прямо на неизменяемый факт журнала, без акта-
 * документа. Инвариант полноты обязательных полей — в домене, проверен в {@see RecordIssuanceTest}; здесь же —
 * печать журнала поверх прямой записи: {@see RequirementCardProjector} больше не привязан к акту-документу,
 * его лог — весь журнал фактов требования, включая записи без документа.
 */
final class InstructionIssuanceTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait; // даёт randomTin()

    private const LABEL = 'Повторный инструктаж на рабочем месте';

    public function test_recorded_instruction_carries_details_to_journal_fact(): void
    {
        [$bus, $repo, $em, $profileId, $key] = $this->setupNonMaterial();

        $bus->execute(new RecordIssuanceCommand(
            $profileId, $this->requirementId, '2026-08-05',
            [[
                'obligationKey' => $key,
                'date' => '2026-08-05',
                'instruction' => [
                    'instruction_kind' => 'Повторный',
                    'instructor_fio' => 'Шумилов Александр Николаевич',
                    'instructor_doc' => 'Удостоверение № 1327339, 06.12.2021',
                ],
            ]],
        ));
        $em->clear();

        $pc = $repo->findByProfile($profileId);
        self::assertNotNull($pc);
        $record = $pc->recordsForRequirement($this->requirementId)[0] ?? null;
        self::assertNotNull($record);
        $details = $record->instructionDetails();
        self::assertNotNull($details, 'поля инструктажа сохранены на факте');
        self::assertSame('Повторный', $details->get('instruction_kind'));
        self::assertSame('Шумилов Александр Николаевич', $details->get('instructor_fio'));

        // Карточка печатает журнал целиком: прямая запись без акта-документа всё равно попадает в лог.
        $c = self::getContainer();
        $requirement = $c->get(RequirementRepositoryInterface::class)->findOneById($this->requirementId);
        self::assertNotNull($requirement);
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $c->get(QueryBusInterface::class)->execute(new GetProfileQuery($profileId));
        self::assertNotNull($profileResult->profile);

        $data = $c->get(RequirementCardProjector::class)->project($pc, $profileResult->profile, $requirement, new \DateTimeImmutable('2026-08-10'));
        $log = $data->get('log');
        self::assertInstanceOf(RepeatValue::class, $log);
        self::assertCount(1, $log->rows, 'в логе ровно одна запись — прямая дозапись, без акта');
        self::assertSame('Повторный', $log->rows[0]['instruction_kind'], 'поля инструктажа всплыли в лог плоскими ключами');
        self::assertSame('Шумилов Александр Николаевич', $log->rows[0]['instructor_fio']);
    }

    private string $requirementId = '';

    /**
     * @return array{0: CommandBusInterface, 1: ProfileComplianceRepositoryInterface, 2: EntityManagerInterface, 3: string, 4: string}
     */
    private function setupNonMaterial(): array
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $bus = $c->get(CommandBusInterface::class);
        $repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $em = $c->get(EntityManagerInterface::class);

        $pos = $bus->execute(new CreatePositionCommand('Техинспектор '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $bus->execute(new CreateCounterpartyCommand('ООО Литум '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $bus->execute(new CreateDepartmentCommand($org->id, 'Отдел ТС '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profile = $bus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Ванжин', firstName: 'Николай', middleName: 'Сергеевич',
            positionId: $pos->id, organizationId: $org->id, departmentId: $dept->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: null, shoes: null, headgear: null, respirator: null, gloves: null, height: null, gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);

        $req = $bus->execute(new SaveRequirementCommand(
            null, 'Журнал инструктажа на рабочем месте', 'non_material', [$pos->id],
            [['label' => self::LABEL, 'cadenceKind' => 'periodic', 'cadenceNumber' => '6', 'cadenceUnit' => 'month', 'basis' => 'ГОСТ 12.0.004']],
            journalKind: 'workplace_ot',
        ));
        \assert($req instanceof SaveRequirementCommandResult);
        $this->requirementId = $req->id;

        $c->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return [$bus, $repo, $em, $profile->id, TrackedObligation::keyOf($req->id, self::LABEL)];
    }
}
