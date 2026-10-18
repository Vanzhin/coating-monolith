<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQuery;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

/**
 * Единая точка пересборки проекции учёта. Норма (Вариант B) агрегатом `ProfileCompliance` не материализуется —
 * она живёт только в `Requirement`; материализует её read-модель {@see ComplianceObligationProjector}. Этот
 * сервис (а) гарантирует существование учёта человека (журналу/№ карточки должно куда писать — заводится лениво
 * при первом касании) и (б) делегирует пересборку строк проекции проектору. Фильтры комбинируются, все пусты →
 * пересобираем всё:
 *  - requirementIds — какие требования пересобрать у покрытых ими профилей.
 *  - positionIds    — профили этих должностей.
 *  - profileIds     — конкретные сотрудники.
 */
final readonly class ComplianceProjectionRebuilder
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private RequirementRepositoryInterface $requirements,
        private QueryBusInterface $queryBus,
        private ComplianceObligationProjector $projector,
    ) {
    }

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

        foreach (array_values(array_unique($targets)) as $profileId) {
            $this->ensureProfileCompliance($profileId);
        }

        // Проектор сам резолвит цели из тех же фильтров (самодостаточен — переживёт снятие этого сервиса
        // в финальной задаче). Материализация норменных/персональных строк — целиком его забота.
        $this->projector->projectMany(
            [] !== $reqIds ? new StringCollection(...$reqIds) : null,
            [] !== $posIds ? new StringCollection(...$posIds) : null,
            [] !== $profIds ? new StringCollection(...$profIds) : null,
        );
    }

    /**
     * Гарантирует существование учёта человека — без него журналу (`RecordIssuance`/`AddToWriteOffDraft`)
     * некуда писать факты. Норму НЕ материализует (Вариант B): пустой агрегат, норму читает проектор.
     */
    private function ensureProfileCompliance(string $profileId): void
    {
        if (null === $this->repository->findByProfile($profileId)) {
            $this->repository->add(new ProfileCompliance(Uuid::v7(), $profileId));
        }
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
}
