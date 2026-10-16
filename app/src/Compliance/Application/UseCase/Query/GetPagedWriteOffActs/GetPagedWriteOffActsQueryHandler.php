<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetPagedWriteOffActs;

use App\Compliance\Application\DTO\WriteOffAct\WriteOffActListItemDTO;
use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Список актов списания — кросс-организационный, только админ/система ({@see ComplianceAccessControl::canManage}).
 * Имена требования/организации на странице не хранятся на акте — резолвим батчем ПОСЛЕ findByFilter: требование —
 * циклом по уникальным requirementId страницы (их мало, не нужен findByIds), организация — у акта нет своей
 * сущности (это {@see \App\Shared\Domain\ValueObject\Reference} на профиле), поэтому берём ПЕРВЫЙ profileId
 * каждого акта и резолвим орг. титул ОДНИМ батч-запросом {@see GetProfilesByIdsQuery} ко всей странице.
 */
final readonly class GetPagedWriteOffActsQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private WriteOffActRepositoryInterface $writeOffActRepository,
        private RequirementRepositoryInterface $requirementRepository,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetPagedWriteOffActsQuery $query): GetPagedWriteOffActsQueryResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $paginator = $this->writeOffActRepository->findByFilter($query->filter);
        /** @var list<WriteOffAct> $acts */
        $acts = array_values($paginator->items);

        $requirementNames = $this->resolveRequirementNames($acts);
        $organizationTitlesByPrimaryProfile = $this->resolveOrganizationTitlesByPrimaryProfile($acts);

        $items = array_map(
            function (WriteOffAct $act) use ($requirementNames, $organizationTitlesByPrimaryProfile): WriteOffActListItemDTO {
                $primaryProfileId = $act->profileIds()[0] ?? null;

                return new WriteOffActListItemDTO(
                    id: $act->getId(),
                    status: $act->status(),
                    actNumber: $act->actNumber(),
                    actDate: $act->actDate(),
                    requirementName: $requirementNames[$act->requirementId()] ?? '',
                    organizationTitle: null !== $primaryProfileId ? ($organizationTitlesByPrimaryProfile[$primaryProfileId] ?? '') : '',
                    lineCount: count($act->lines()),
                    profileCount: count($act->profileIds()),
                );
            },
            $acts,
        );

        $pager = $query->filter->pager;

        return new GetPagedWriteOffActsQueryResult($items, new Pager($pager->page, $pager->perPage, $paginator->total));
    }

    /**
     * @param list<WriteOffAct> $acts
     *
     * @return array<string, string> requirementId → название
     */
    private function resolveRequirementNames(array $acts): array
    {
        $names = [];
        foreach ($acts as $act) {
            $requirementId = $act->requirementId();
            if (isset($names[$requirementId])) {
                continue; // уже резолвили — требований на странице заметно меньше, чем актов
            }
            $requirement = $this->requirementRepository->findOneById($requirementId);
            $names[$requirementId] = $requirement?->getName() ?? '';
        }

        return $names;
    }

    /**
     * @param list<WriteOffAct> $acts
     *
     * @return array<string, string> profileId (первый у акта) → название организации, ОДНИМ запросом на страницу
     */
    private function resolveOrganizationTitlesByPrimaryProfile(array $acts): array
    {
        $primaryProfileIds = [];
        foreach ($acts as $act) {
            $primaryProfileId = $act->profileIds()[0] ?? null;
            if (null !== $primaryProfileId) {
                $primaryProfileIds[] = $primaryProfileId;
            }
        }
        $uniqueProfileIds = array_values(array_unique($primaryProfileIds));
        if ([] === $uniqueProfileIds) {
            return [];
        }

        /** @var GetProfilesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery(new StringCollection(...$uniqueProfileIds)));

        $titlesByProfileId = [];
        foreach ($result->profiles as $profile) {
            $titlesByProfileId[$profile->id] = $profile->organizationTitle;
        }

        return $titlesByProfileId;
    }
}
