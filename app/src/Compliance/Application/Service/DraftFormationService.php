<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Application\ReadModel\ComplianceBucketResolver;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
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
        private ComplianceBucketResolver $buckets,
    ) {
    }

    /** Черновик по (человек × требование), если есть что выдавать и открытого ещё нет. Не сохраняет — зовущий сохраняет. */
    public function formForProfileRequirement(ProfileCompliance $profileCompliance, string $requirementId, \DateTimeImmutable $now): bool
    {
        if (null !== $profileCompliance->openDraftFor($requirementId) || !$this->hasDue($profileCompliance, $requirementId, $now)) {
            return false;
        }
        $profileCompliance->formDraft(Uuid::v7(), $requirementId, $now);

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
