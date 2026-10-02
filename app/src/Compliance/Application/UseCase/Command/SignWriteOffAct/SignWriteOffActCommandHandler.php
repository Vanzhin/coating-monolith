<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SignWriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\DraftFormationService;
use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Compliance\Domain\ValueObject\WriteOffCommissionMember;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Оформление акта списания: причины проставляются по позициям, комиссия собирается снимком из профилей
 * организации (должность/ФИО), скан промоутится, домен замораживает акт И гасит списанные факты + пересчитывает
 * (эффект наступает ТОЛЬКО здесь). Освободившиеся позиции → новый черновик выдачи ({@see DraftFormationService}).
 * Отказ домена → снимаем осиротевший скан.
 */
final readonly class SignWriteOffActCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private QueryBusInterface $queryBus,
        private FileStorage $storage,
        private ObligationDueCalculator $calculator,
        private DraftFormationService $formation,
    ) {
    }

    public function __invoke(SignWriteOffActCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $now = new \DateTimeImmutable();
        $actDate = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($command->actDate))
            ?: throw new AppException('Укажите дату акта списания.');

        $requirementId = null;
        foreach ($profileCompliance->getWriteOffActs() as $act) {
            if ($act->getId() === $command->writeOffActId) {
                $requirementId = $act->requirementId();
                break;
            }
        }
        if (null === $requirementId) {
            throw new AppException('Акт списания не найден.');
        }

        $commission = $this->resolveCommission($command->representativeProfileId, $command->memberProfileIds);

        $staged = trim((string) $command->stagedFileId);
        if ('' === $staged) {
            throw new AppException('Приложите скан подписанного акта списания.');
        }
        $fileId = $this->storage->promote($staged, RequirementScanPurpose::WriteOffActScan, $profileCompliance->getId())->id();

        try {
            $profileCompliance->signWriteOffAct($command->writeOffActId, $commission, $command->actNumber, $actDate, $fileId, $now, $this->calculator);
            // позиции освободились → по норме заводим черновик новой выдачи
            $this->formation->formForProfileRequirement($profileCompliance, $requirementId, $actDate);
            $this->repository->add($profileCompliance);
        } catch (\Throwable $e) {
            $this->storage->remove($fileId);
            throw $e;
        }
    }

    /**
     * @param list<string> $memberProfileIds
     */
    private function resolveCommission(string $representativeProfileId, array $memberProfileIds): WriteOffCommission
    {
        $ids = new StringCollection($representativeProfileId, ...$memberProfileIds);
        /** @var GetProfilesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery($ids));

        $byId = [];
        foreach ($result->profiles as $profile) {
            $byId[$profile->id] = $profile;
        }

        $members = [];
        foreach ($memberProfileIds as $memberId) {
            $members[] = $this->member($byId[$memberId] ?? throw new AppException('Член комиссии не найден.'));
        }

        return new WriteOffCommission(
            $this->member($byId[$representativeProfileId] ?? throw new AppException('Представитель комиссии не найден.')),
            ...$members,
        );
    }

    private function member(ProfileDTO $profile): WriteOffCommissionMember
    {
        $fio = $profile->lastName.' '.mb_substr($profile->firstName, 0, 1).'.'
            .(null !== $profile->middleName && '' !== $profile->middleName ? mb_substr($profile->middleName, 0, 1).'.' : '');

        return new WriteOffCommissionMember($profile->positionTitle, trim($fio));
    }
}
