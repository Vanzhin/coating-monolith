<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveDraft\SaveDraftCommand;
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
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\Service\UuidService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Сетап учёта соответствия для функциональных тестов: заводит должность/организацию/отдел/профиль + норму
 * (материальное требование с одной позицией) и строит проекцию. Требует залогиненного менеджера (canManage).
 */
trait EnrollsComplianceTrait
{
    /** @return array{profileId: string, requirementId: string, key: string} */
    private function enrollCompliance(string $itemLabel = 'Перчатки'): array
    {
        $bus = static::getContainer()->get(CommandBusInterface::class);

        $pos = $bus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $bus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $bus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profile = $bus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Иванов', firstName: 'Иван', middleName: 'Иванович',
            positionId: $pos->id, organizationId: $org->id, departmentId: $dept->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);

        $req = $bus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$pos->id],
            [['label' => $itemLabel, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return ['profileId' => $profile->id, 'requirementId' => $req->id, 'key' => TrackedObligation::keyOf($req->id, $itemLabel)];
    }

    /** Выдать карточку (черновик → подписать) и вернуть id подписанного акта выдачи. $date — дата выдачи (для срока). */
    private function issueCard(string $profileId, string $requirementId, string $key, string $amount = '10', string $date = '2026-06-01'): string
    {
        $bus = static::getContainer()->get(CommandBusInterface::class);
        $repo = static::getContainer()->get(ProfileComplianceRepositoryInterface::class);
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $bus->execute(new FormDraftCommand($profileId, $requirementId));
        $em->clear();
        $draft = $repo->findByProfile($profileId)?->openDraftFor($requirementId);
        \assert(null !== $draft);

        $bus->execute(new SaveDraftCommand(
            $profileId, $draft->getId(), $date,
            [['obligationKey' => $key, 'amount' => $amount, 'unit' => 'pair']],
            'К-1', 'Петров П. П.',
            $this->stageComplianceScan(),
        ));
        $em->clear();
        $signed = $repo->findByProfile($profileId)?->signedDocumentsFor($requirementId) ?? [];
        \assert([] !== $signed);

        return $signed[0]->getId();
    }

    private function stageComplianceScan(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'scan').'.pdf';
        file_put_contents($tmp, "%PDF-1.4\n%signed card\n");

        return static::getContainer()->get(FileStorage::class)
            ->stage('u-'.uniqid('', true), new UploadedFile($tmp, 'card.pdf', 'application/pdf', null, true))
            ->id();
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
}
