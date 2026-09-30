<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Command\RemoveFulfillment\RemoveFulfillmentCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
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
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\AuthenticatesActorTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecordIssuanceTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private ComplianceProjectionRebuilder $rebuilder;
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->rebuilder = $c->get(ComplianceProjectionRebuilder::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    /** @return array{profileId: string, requirementId: string, key: string} */
    private function enrolled(): array
    {
        $pos = $this->commandBus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $this->commandBus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $this->commandBus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profile = $this->commandBus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Иванов', firstName: 'Иван', middleName: 'Иванович',
            positionId: $pos->id, organizationId: $org->id, departmentId: $dept->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);

        $req = $this->commandBus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$pos->id],
            [['label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        $this->rebuilder->rebuildForProfile($profile->id);

        return ['profileId' => $profile->id, 'requirementId' => $req->id, 'key' => TrackedObligation::keyOf($req->id, 'Перчатки')];
    }

    private function randomTin(): string
    {
        $digits = '';
        for ($i = 0; $i < 9; ++$i) {
            $digits .= random_int(0, 9);
        }
        $weights = [2, 4, 10, 3, 5, 9, 4, 6, 8];
        $sum = 0;
        foreach ($weights as $i => $w) {
            $sum += $w * (int) $digits[$i];
        }

        return $digits.($sum % 11 % 10);
    }

    public function test_record_issuance_recomputes_dates(): void
    {
        ['profileId' => $profileId, 'requirementId' => $reqId, 'key' => $key] = $this->enrolled();

        $this->commandBus->execute(new RecordIssuanceCommand(
            $profileId, $reqId, '2026-03-01',
            [['obligationKey' => $key, 'amount' => '10', 'unit' => 'pair']],
        ));

        static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getRecords());
        $ob = $pc->getObligations()[0];
        self::assertSame('2026-03-01', $ob->lastFulfilledAt()?->format('Y-m-d'));
        self::assertSame('2027-03-01', $ob->nextDueAt()?->format('Y-m-d'));
    }

    public function test_remove_fulfillment_rolls_back_dates(): void
    {
        ['profileId' => $profileId, 'requirementId' => $reqId, 'key' => $key] = $this->enrolled();
        $this->commandBus->execute(new RecordIssuanceCommand(
            $profileId, $reqId, '2026-03-01', [['obligationKey' => $key, 'amount' => '10', 'unit' => 'pair']],
        ));

        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);
        $recordId = $pc->getRecords()[0]->getId();

        $this->commandBus->execute(new RemoveFulfillmentCommand($profileId, $recordId));

        static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->clear();
        $reloaded = $this->repo->findByProfile($profileId);
        self::assertNotNull($reloaded);
        self::assertCount(0, $reloaded->getRecords());
        self::assertNull($reloaded->getObligations()[0]->lastFulfilledAt());
    }
}
