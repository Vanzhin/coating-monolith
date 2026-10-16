<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\AddToWriteOffDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\Uid\Uuid;

/**
 * Наполняет org-scoped корзину (черновик {@see WriteOffAct}) held-фактами человека: выбранными позициями
 * («Списать выбранные») или всеми инвентарными сразу («Списать всё», `obligationKeys === null`). Черновик —
 * один на пару (требование × организация профиля), поэтому несколько людей одной организации по одному
 * требованию попадают в один и тот же акт. Инварианты строк — в домене ({@see WriteOffAct::addLinesForProfile}).
 */
final readonly class AddToWriteOffDraftCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private WriteOffActRepositoryInterface $writeOffActRepository,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(AddToWriteOffDraftCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($command->profileId));
        $profile = $profileResult->profile ?? throw new AppException('Сотрудник не найден.');
        $organizationId = $profile->organizationId;

        $act = $this->writeOffActRepository->findOpenDraft($command->requirementId, $organizationId)
            ?? new WriteOffAct(Uuid::v7(), $command->requirementId, $organizationId, new \DateTimeImmutable());

        $lines = array_map(
            static fn (FulfillmentRecord $fact): array => [
                'recordId' => $fact->getId(),
                'labelSnapshot' => $profileCompliance->obligationLabelOf($fact->obligationKey()),
                'quantity' => $fact->heldAmount(),
            ],
            $this->selectHeldFacts($profileCompliance, $command->requirementId, $command->obligationKeys),
        );
        $act->addLinesForProfile($command->profileId, $lines);

        $this->writeOffActRepository->add($act);
    }

    /**
     * Held-факты человека по требованию, целевые для корзины. Явный выбор («Списать выбранные») — только
     * указанные позиции; `null` («Списать всё») — все ИНВЕНТАРНЫЕ held>0 (материальный факт — у него есть
     * `quantity`; расходные/нематериальные позиции списанию не подлежат и в «всё» не попадают).
     *
     * @param ?list<string> $obligationKeys
     *
     * @return list<FulfillmentRecord>
     */
    private function selectHeldFacts(ProfileCompliance $profileCompliance, string $requirementId, ?array $obligationKeys): array
    {
        $wanted = null === $obligationKeys ? null : array_fill_keys($obligationKeys, true);

        $facts = [];
        foreach ($profileCompliance->recordsForRequirement($requirementId) as $fact) {
            if ($fact->heldAmount() <= 0.0) {
                continue;
            }
            if (null === $wanted) {
                if (null === $fact->quantity()) {
                    continue; // нематериальный факт — списанию не подлежит
                }
            } elseif (!isset($wanted[$fact->obligationKey()])) {
                continue;
            }
            $facts[] = $fact;
        }

        return $facts;
    }
}
