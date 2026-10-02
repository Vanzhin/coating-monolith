<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Personnel\Application\UseCase\Query\GetProfileByUserUlid\GetProfileByUserUlidQuery;
use App\Personnel\Application\UseCase\Query\GetProfileByUserUlid\GetProfileByUserUlidQueryResult;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByFilter\GetProfileIdsByFilterQuery;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByFilter\GetProfileIdsByFilterQueryResult;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Общий скоуп дашборда для списка и overview: owner-запирание не-админа на свой профиль (как список
 * отчётов), пред-сужение позиции/поиска через Personnel, батч-резолв ФИО. Отдел сужается в репозитории.
 */
final readonly class ComplianceDashboardScope
{
    public function __construct(
        private ComplianceAccessControl $access,
        private QueryBusInterface $queryBus,
    ) {
    }

    /** null — без сужения (только отдел в репозитории); StringCollection — точный набор (пустой = никого). */
    public function restrictProfileIds(ComplianceDashboardFilter $filter): ?StringCollection
    {
        if (!$this->access->isManager()) {
            /** @var GetProfileByUserUlidQueryResult $result */
            $result = $this->queryBus->execute(new GetProfileByUserUlidQuery($this->access->currentUserId()));
            $ownProfileId = $result->profile?->id;

            return new StringCollection(...(null !== $ownProfileId ? [$ownProfileId] : []));
        }

        if ($filter->profileIds->count() > 0) {
            return $filter->profileIds; // выбранные люди (чипы) / deeplink из уведомления
        }

        if ($filter->positionIds->count() > 0 || (null !== $filter->q && '' !== trim($filter->q))) {
            /** @var GetProfileIdsByFilterQueryResult $result */
            $result = $this->queryBus->execute(new GetProfileIdsByFilterQuery($filter->positionIds, $filter->q));

            return $result->profileIds;
        }

        return null;
    }

    /**
     * @param list<ProfileCompliance> $projections
     *
     * @return array<string, ProfileDTO>
     */
    public function profilesById(array $projections): array
    {
        $ids = array_map(static fn (ProfileCompliance $p): string => $p->getProfileId(), $projections);
        /** @var GetProfilesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery(new StringCollection(...$ids)));

        $byId = [];
        foreach ($result->profiles as $profile) {
            $byId[$profile->id] = $profile;
        }

        return $byId;
    }
}
