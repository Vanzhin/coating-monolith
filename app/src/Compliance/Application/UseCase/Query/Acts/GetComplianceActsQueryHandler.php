<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Acts;

use App\Compliance\Application\DTO\Acts\ComplianceActRowDTO;
use App\Compliance\Application\Service\ComplianceDashboardScope;
use App\Compliance\Domain\Aggregate\ProfileCompliance\RequirementDocument;
use App\Compliance\Domain\Repository\ComplianceActRepositoryInterface;
use App\Compliance\Domain\Repository\ComplianceActsFilter;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Список актов выдачи: owner-скоуп (не-админ → свой профиль) + пред-сужение по ФИО/должности — через общий
 * {@see ComplianceDashboardScope}, затем единый {@see ComplianceActRepositoryInterface::findByFilter} (фильтр,
 * сортировка, пагинация — в репозитории). ФИО профиля и имя требования (кросс-контекст) обогащаем здесь батчем.
 */
final readonly class GetComplianceActsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ComplianceActRepositoryInterface $repository,
        private RequirementRepositoryInterface $requirements,
        private ComplianceDashboardScope $scope,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetComplianceActsQuery $query): GetComplianceActsQueryResult
    {
        $pager = $query->pager ?? Pager::fromPage();

        $filter = new ComplianceActsFilter(
            restrictProfileIds: $this->scope->restrictProfileIds($query->profileIds, $query->positionIds, $query->q),
            requirementId: $query->requirementId,
            status: $query->status,
            dateFrom: $query->dateFrom,
            dateTo: $query->dateTo,
            sort: $query->sort,
            pager: $pager,
        );

        // Репозиторий отдаёт уже отфильтрованную, упорядоченную (дата выдачи) и пагинированную страницу;
        // здесь только обогащаем её ФИО/именем требования для отображения (кросс-контекст Personnel/Requirement).
        $result = $this->repository->findByFilter($filter);

        /** @var list<RequirementDocument> $documents */
        $documents = $result->items;
        $profilesById = $this->resolveProfiles($documents);
        $requirementNames = $this->resolveRequirementNames($documents);
        $positionsByDoc = $this->repository->countPositionsByDocuments(array_map(static fn (RequirementDocument $d): string => $d->getId(), $documents));

        $rows = [];
        foreach ($documents as $document) {
            $profile = $profilesById[$this->profileIdOf($document)] ?? null;
            if (null === $profile) {
                continue; // профиль удалён — акт не показываем
            }
            $rows[] = new ComplianceActRowDTO(
                documentId: $document->getId(),
                profileId: $profile->id,
                personFio: trim(sprintf('%s %s %s', $profile->lastName, $profile->firstName, $profile->middleName ?? '')),
                positionTitle: $profile->positionTitle,
                departmentTitle: $profile->departmentTitle,
                requirementId: $document->requirementId(),
                requirementName: $requirementNames[$document->requirementId()] ?? '',
                actNumber: $document->actNumber(),
                date: $document->signedAt() ?? $document->createdAt(),
                signed: $document->isSigned(),
                positionsCount: $positionsByDoc[$document->getId()] ?? 0,
            );
        }

        return new GetComplianceActsQueryResult($rows, new Pager($pager->page, $pager->perPage, $result->total));
    }

    /**
     * @param list<RequirementDocument> $documents
     *
     * @return array<string, ProfileDTO>
     */
    private function resolveProfiles(array $documents): array
    {
        $ids = array_values(array_unique(array_map(fn (RequirementDocument $d): string => $this->profileIdOf($d), $documents)));
        if ([] === $ids) {
            return [];
        }
        /** @var GetProfilesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery(new StringCollection(...$ids)));

        $byId = [];
        foreach ($result->profiles as $profile) {
            $byId[$profile->id] = $profile;
        }

        return $byId;
    }

    /**
     * @param list<RequirementDocument> $documents
     *
     * @return array<string, string> requirementId → имя
     */
    private function resolveRequirementNames(array $documents): array
    {
        $names = [];
        foreach ($documents as $document) {
            $rid = $document->requirementId();
            if (!isset($names[$rid])) {
                $names[$rid] = $this->requirements->findOneById($rid)?->getName() ?? '';
            }
        }

        return $names;
    }

    private function profileIdOf(RequirementDocument $document): string
    {
        return $document->profileCompliance()->getProfileId();
    }
}
