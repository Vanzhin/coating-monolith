<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQuery;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

/**
 * Пересобирает проекцию учёта человека из ЖИВОЙ нормы (требования его должности) + фактов + личных
 * исключений. Норму не морозим — читаем текущую. Даты считаем из фактов ({@see ObligationDueCalculator}).
 * `active` (трекать сроки) выводим из наличия подписанного акта требования, а не несём по строке. Позиции,
 * ушедшие из нормы: без фактов — убираем, с фактами — оставляем (история). Кросс-контекст к Personnel —
 * через query-шину.
 */
final readonly class ComplianceProjectionRebuilder
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private RequirementRepositoryInterface $requirements,
        private QueryBusInterface $queryBus,
        private ObligationDueCalculator $calculator,
    ) {
    }

    /**
     * Единая точка пересборки проекции учёта. Фильтры комбинируются, все пусты → пересобираем всё:
     *  - requirementIds — СРЕЗ: какие требования пересобирать у профиля (точечно). Пусто → все требования должности.
     *  - positionIds    — профили этих должностей.
     *  - profileIds     — конкретные сотрудники.
     * Множество профилей = объединение: явные сотрудники + по должностям + (если заданы ТОЛЬКО требования)
     * покрытые этими требованиями. Ничего не задано → все заведённые учёты.
     */
    public function rebuild(
        ?StringCollection $requirementIds = null,
        ?StringCollection $positionIds = null,
        ?StringCollection $profileIds = null,
    ): void {
        $reqIds = $requirementIds?->getList() ?? [];
        $posIds = $positionIds?->getList() ?? [];
        $profIds = $profileIds?->getList() ?? [];

        $targets = $profIds;
        if ([] !== $posIds) {
            $targets = array_merge($targets, $this->profileIdsOnPositions($posIds));
        }
        if ([] !== $reqIds && [] === $profIds && [] === $posIds) {
            $targets = array_merge($targets, $this->profileIdsCoveredByRequirements($reqIds));
        }
        if ([] === $reqIds && [] === $posIds && [] === $profIds) {
            $targets = $this->repository->findAllProfileIds(); // без фильтров — всё
        }

        $scope = [] !== $reqIds ? $reqIds : null; // список требований-срезов или null = все требования должности
        foreach (array_values(array_unique($targets)) as $profileId) {
            $this->rebuildProfile($profileId, $scope);
        }
    }

    /**
     * Пересборка одного профиля. $onlyRequirementIds = null → полная (все требования должности, для события
     * профиля). Список → точечная: трогаем и пересчитываем только срезы этих требований, чужие (напр. журнал)
     * НЕ задеваем — даже заводя новую карточку (полный набор появляется на событии профиля, не при правке нормы).
     *
     * @param list<string>|null $onlyRequirementIds
     */
    private function rebuildProfile(string $profileId, ?array $onlyRequirementIds): void
    {
        $profileCompliance = $this->repository->findByProfile($profileId) ?? new ProfileCompliance(Uuid::v7(), $profileId);

        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $result->profile) {
            $this->repository->add($profileCompliance); // профиль недоступен — оставляем как есть

            return;
        }
        $departmentId = $result->profile->departmentId;
        $positionId = $result->profile->positionId;

        // Пересобираемый срез нормы: все требования должности (полностью) или заданные (точечно) — но только те
        // из заданных, что реально покрывают должность профиля, иначе чужому профилю завелись бы лишние позиции.
        $requirements = [];
        if (null === $onlyRequirementIds) {
            $requirements = $this->requirements->findByPositionId($positionId);
        } else {
            foreach ($onlyRequirementIds as $id) {
                $requirement = $this->requirements->findOneById($id);
                if (null !== $requirement && in_array($positionId, $requirement->getPositionIds()->getList(), true)) {
                    $requirements[] = $requirement;
                }
            }
        }

        $keysWithFacts = [];
        foreach ($profileCompliance->getRecords() as $record) {
            $keysWithFacts[$record->obligationKey()] = true;
        }
        $excluded = $profileCompliance->getExcludedKeys()->getList();

        $desired = [];
        foreach ($requirements as $requirement) {
            $active = [] !== $profileCompliance->signedDocumentsFor($requirement->getId()); // был подписанный акт ⇒ трекинг включён
            foreach ($requirement->getItems() as $item) {
                $key = TrackedObligation::keyOf($requirement->getId(), $item->label());
                if (in_array($key, $excluded, true)) {
                    continue;
                }
                $desired[$key] = true;
                $obligation = new TrackedObligation(
                    Uuid::v7(), $profileCompliance, $requirement->getId(), $requirement->getName(),
                    $item->label(), $item->type(), $item->cadence(),
                    $item instanceof MaterialItem ? $item->quantity() : null,
                    $departmentId, TrackedObligation::ORIGIN_NORM,
                );
                $obligation->setActive($active);
                $profileCompliance->putObligation($obligation);
            }
        }

        // Осиротевшие ORIGIN_NORM-обязанности без фактов убираем, но только в пределах пересобираемого среза:
        // при точечной пересборке чужие требования не трогаем.
        foreach ($profileCompliance->getObligations() as $obligation) {
            if (TrackedObligation::ORIGIN_NORM !== $obligation->origin()) {
                continue;
            }
            if (null !== $onlyRequirementIds && !$this->keyInAnyRequirement($obligation->key(), $onlyRequirementIds)) {
                continue;
            }
            if (isset($desired[$obligation->key()]) || isset($keysWithFacts[$obligation->key()])) {
                continue;
            }
            $profileCompliance->removeObligationByKey($obligation->key());
        }

        if (null === $onlyRequirementIds) {
            $profileCompliance->recomputeAll($this->calculator);
        } else {
            foreach ($onlyRequirementIds as $requirementId) {
                $profileCompliance->recomputeRequirement($requirementId, $this->calculator);
            }
        }
        $this->repository->add($profileCompliance);
    }

    /**
     * @param list<string> $positionIds
     *
     * @return list<string>
     */
    private function profileIdsOnPositions(array $positionIds): array
    {
        /** @var GetProfileIdsByPositionsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileIdsByPositionsQuery(new StringCollection(...$positionIds)));

        return $result->profileIds;
    }

    /**
     * @param list<string> $requirementIds
     *
     * @return list<string>
     */
    private function profileIdsCoveredByRequirements(array $requirementIds): array
    {
        $positionIds = [];
        foreach ($requirementIds as $requirementId) {
            $requirement = $this->requirements->findOneById($requirementId);
            if (null !== $requirement) {
                $positionIds = array_merge($positionIds, $requirement->getPositionIds()->getList());
            }
        }

        return [] === $positionIds ? [] : $this->profileIdsOnPositions(array_values(array_unique($positionIds)));
    }

    /** @param list<string> $requirementIds */
    private function keyInAnyRequirement(string $key, array $requirementIds): bool
    {
        foreach ($requirementIds as $requirementId) {
            if (TrackedObligation::keyBelongsToRequirement($key, $requirementId)) {
                return true;
            }
        }

        return false;
    }
}
