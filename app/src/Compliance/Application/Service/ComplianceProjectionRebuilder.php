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

    public function rebuildForProfile(string $profileId): void
    {
        $profileCompliance = $this->repository->findByProfile($profileId) ?? new ProfileCompliance(Uuid::v7(), $profileId);

        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $result->profile) {
            $this->repository->add($profileCompliance); // профиль недоступен — оставляем как есть

            return;
        }
        $positionId = $result->profile->positionId;
        $departmentId = $result->profile->departmentId;

        $keysWithFacts = [];
        foreach ($profileCompliance->getRecords() as $record) {
            $keysWithFacts[$record->obligationKey()] = true;
        }
        $excluded = $profileCompliance->getExcludedKeys()->getList();

        $desired = [];
        foreach ($this->requirements->findByPositionId($positionId) as $requirement) {
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

        foreach ($profileCompliance->getObligations() as $obligation) {
            if (TrackedObligation::ORIGIN_NORM !== $obligation->origin()) {
                continue;
            }
            if (isset($desired[$obligation->key()]) || isset($keysWithFacts[$obligation->key()])) {
                continue;
            }
            $profileCompliance->removeObligationByKey($obligation->key());
        }

        $profileCompliance->recomputeAll($this->calculator);
        $this->repository->add($profileCompliance);
    }

    public function rebuildForRequirement(string $requirementId): void
    {
        $requirement = $this->requirements->findOneById($requirementId);
        if (null === $requirement) {
            return; // требование удалено — пересборка по положениям недоступна (обработка удаления — позже)
        }

        /** @var GetProfileIdsByPositionsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileIdsByPositionsQuery(
            new StringCollection(...$requirement->getPositionIds()->getList()),
        ));
        foreach ($result->profileIds as $profileId) {
            $this->rebuildForProfile($profileId);
        }
    }
}
