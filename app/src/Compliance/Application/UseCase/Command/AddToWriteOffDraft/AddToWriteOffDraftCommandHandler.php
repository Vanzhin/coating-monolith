<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\AddToWriteOffDraft;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
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

        $facts = $this->selectHeldFacts($profileCompliance, $command->requirementId, $command->obligationKeys);
        $existingAct = $this->writeOffActRepository->findOpenDraft($command->requirementId, $organizationId);
        if ([] === $facts && null === $existingAct) {
            return; // добавить нечего, открытого черновика нет — пустой акт не плодим
        }
        $act = $existingAct ?? new WriteOffAct(Uuid::v7(), $command->requirementId, $organizationId, new \DateTimeImmutable());

        $lines = array_map(
            static fn (FulfillmentRecord $fact): array => [
                'recordId' => $fact->getId(),
                'labelSnapshot' => $profileCompliance->obligationLabelOf($fact->obligationKey()),
                'quantity' => $fact->heldAmount(),
            ],
            $facts,
        );
        $act->addLinesForProfile($command->profileId, $lines);

        $this->writeOffActRepository->add($act);
    }

    /**
     * Held-факты человека по требованию, целевые для корзины. Явный выбор («Списать выбранные») — только
     * указанные позиции; `null` («Списать всё») — все ИНВЕНТАРНЫЕ held>0. В обоих случаях расходные (ДСИЗ,
     * {@see \App\Compliance\Domain\Type\MaterialMode::Consumable}) и нематериальные факты НИКОГДА не попадают
     * в списание — источник истины бэк, явный выбор их ключа клиентом игнорируется.
     *
     * @param ?list<string> $obligationKeys
     *
     * @return list<FulfillmentRecord>
     */
    private function selectHeldFacts(ProfileCompliance $profileCompliance, string $requirementId, ?array $obligationKeys): array
    {
        $consumableKeys = $this->consumableKeysOf($profileCompliance);
        $wanted = null === $obligationKeys ? null : array_fill_keys($obligationKeys, true);

        $facts = [];
        foreach ($profileCompliance->recordsForRequirement($requirementId) as $fact) {
            if ($fact->heldAmount() <= 0.0 || isset($consumableKeys[$fact->obligationKey()])) {
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

    /**
     * Ключи обязанностей в режиме «расходная» (ДСИЗ) — списанию не подлежат никогда, независимо от выбора
     * клиента. Режим живёт на проекции обязанности ({@see TrackedObligation::isConsumable}), не на факте —
     * сам факт выдачи не знает, инвентарная его позиция или расходная. Нет режима (напр. персональная
     * позиция вне нормы) — трактуем как инвентарь, не исключаем.
     *
     * @return array<string, true>
     */
    private function consumableKeysOf(ProfileCompliance $profileCompliance): array
    {
        $keys = [];
        foreach ($profileCompliance->getObligations() as $obligation) {
            if ($obligation->isConsumable()) {
                $keys[$obligation->key()] = true;
            }
        }

        return $keys;
    }
}
