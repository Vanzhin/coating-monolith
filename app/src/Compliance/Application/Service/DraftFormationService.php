<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQuery;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

/**
 * Единая точка создания черновиков карточек — зовётся из события {@see \App\Compliance\Domain\Event\RequirementChanged},
 * из пакетной/одиночной кнопки и из события профиля. Гарды одни для всех: пустой набор (всё ок) → не создаём,
 * открытый черновик уже есть → пропускаем. Так дублей/мусорных черновиков не бывает.
 */
final readonly class DraftFormationService
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private RequirementRepositoryInterface $requirements,
        private QueryBusInterface $queryBus,
        private ComplianceStatusResolver $buckets,
    ) {
    }

    /**
     * Черновик-корзина по (человек × требование): если есть что выдавать — создаёт черновик (если открытого нет)
     * и наполняет корзину дефицитом; существующий черновик дополняет недостающим (идемпотентно). Не сохраняет —
     * зовущий сохраняет. Возвращает true, если корзина тронута (создана или дополнена).
     */
    public function formForProfileRequirement(ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now): bool
    {
        if (!$this->hasDue($profileCompliance, $requirementId, $now)) {
            return false; // нечего класть в корзину
        }
        if (null === $profileCompliance->openDraftFor($requirementId)) {
            $profileCompliance->formDraft(Uuid::v7(), $requirementId, $now);
        }
        $draft = $profileCompliance->openDraftFor($requirementId);
        if (null === $draft) {
            return false;
        }
        $profileCompliance->topUpDraftFromNorm($draft->getId(), $requirementId, $now);

        return true;
    }

    /** По требованию — всем сотрудникам его должностей (событие нормы / пакетная кнопка). Возвращает число созданных. */
    public function formForRequirement(string $requirementId, \DateTimeImmutable $now): int
    {
        $requirement = $this->requirements->findOneById($requirementId);
        if (null === $requirement) {
            return 0;
        }
        /** @var GetProfileIdsByPositionsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileIdsByPositionsQuery(
            new StringCollection(...$requirement->getPositionIds()->getList()),
        ));

        $created = 0;
        foreach ($result->profileIds as $profileId) {
            $profileCompliance = $this->repository->findByProfile($profileId);
            if (null !== $profileCompliance && $this->formForProfileRequirement($profileCompliance, $requirementId, $now)) {
                $this->repository->add($profileCompliance);
                ++$created;
            }
        }

        return $created;
    }

    /** По человеку — все его требования (новый сотрудник / смена должности). Не сохраняет — зовущий сохраняет. */
    public function formForProfile(ProfileCompliance $profileCompliance, \DateTimeImmutable $now): int
    {
        $created = 0;
        foreach ($this->distinctRequirementIds($profileCompliance) as $requirementId) {
            if ($this->formForProfileRequirement($profileCompliance, $requirementId, $now)) {
                ++$created;
            }
        }

        return $created;
    }

    private function hasDue(ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now): bool
    {
        foreach ($profileCompliance->getObligations() as $obligation) {
            if ($obligation->requirementId() !== $requirementId) {
                continue;
            }
            $bucket = $this->buckets->bucketFor($obligation->isActive(), $obligation->lastFulfilledAt(), $obligation->nextDueAt(), $now, $obligation->quantity()?->amount, $obligation->heldQuantity());
            if (ComplianceBucket::Ok !== $bucket) {
                return true; // ни разу / просрочено / скоро / дефицит количества — есть что выдавать
            }
        }

        return false;
    }

    /** @return list<string> */
    private function distinctRequirementIds(ProfileCompliance $profileCompliance): array
    {
        $ids = [];
        foreach ($profileCompliance->getObligations() as $obligation) {
            $ids[$obligation->requirementId()] = true;
        }

        return array_keys($ids);
    }
}
