<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\Service;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
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
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\AuthenticatesActorTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ComplianceProjectionRebuilderTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ComplianceProjectionRebuilder $rebuilder;
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->rebuilder = $c->get(ComplianceProjectionRebuilder::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    /** @return array{profileId: string, positionId: string} */
    private function createProfileWithPosition(): array
    {
        $posResult = $this->commandBus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($posResult instanceof CreatePositionCommandResult);

        $orgResult = $this->commandBus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($orgResult instanceof CreateCounterpartyCommandResult);

        $deptResult = $this->commandBus->execute(new CreateDepartmentCommand($orgResult->id, 'Цех '.uniqid('', true)));
        \assert($deptResult instanceof CreateDepartmentCommandResult);

        $profileResult = $this->commandBus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Иванов', firstName: 'Иван', middleName: 'Иванович',
            positionId: $posResult->id, organizationId: $orgResult->id, departmentId: $deptResult->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profileResult instanceof CreateProfileCommandResult);

        return ['profileId' => $profileResult->id, 'positionId' => $posResult->id];
    }

    private function saveRequirement(string $positionId): string
    {
        $result = $this->commandBus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$positionId],
            [['label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($result instanceof SaveRequirementCommandResult);

        return $result->id;
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

    public function test_rebuild_for_profile_builds_projection_from_requirement(): void
    {
        ['profileId' => $profileId, 'positionId' => $positionId] = $this->createProfileWithPosition();
        $this->saveRequirement($positionId);

        $this->rebuilder->rebuildForProfile($profileId);

        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getObligations());
        self::assertSame('Перчатки', $pc->getObligations()[0]->label());
        self::assertFalse($pc->getObligations()[0]->isActive()); // документ не подписан
    }

    public function test_rebuild_for_requirement_fans_out_to_covered_profile(): void
    {
        ['profileId' => $profileId, 'positionId' => $positionId] = $this->createProfileWithPosition();
        $reqId = $this->saveRequirement($positionId);

        $this->rebuilder->rebuildForRequirement($reqId);

        $result = $this->queryBus->execute(new GetProfileComplianceQuery($profileId));
        \assert($result instanceof GetProfileComplianceQueryResult);
        self::assertNotNull($result->compliance);
        self::assertCount(1, $result->compliance->obligations);
        // Не подписан → требование не исполнено → красный.
        self::assertSame('red', $result->compliance->obligations[0]->status);
        self::assertSame('red', $result->compliance->worstStatus);
    }
}
