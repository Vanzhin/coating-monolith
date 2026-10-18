<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\ReadModel\ObligationProjection;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
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
 * отчётов), пред-сужение позиции/поиска через Personnel, батч-резолв ФИО. Отдел — читатель фильтрует сам
 * в PHP по {@see ProfileDTO::$departmentId} (строки проекции без сужения по отделу на SQL-уровне).
 */
final readonly class ComplianceDashboardScope
{
    public function __construct(
        private ComplianceAccessControl $access,
        private QueryBusInterface $queryBus,
        private ProfileComplianceRepositoryInterface $profiles,
    ) {
    }

    /**
     * Owner-запирание не-админа на свой профиль + пред-сужение по выбранным людям / должности+поиску.
     * Админ без всякого сужения — ВСЕ профили с учётом (единственный источник их universe без обхода
     * агрегата — id-шки {@see ProfileComplianceRepositoryInterface::findAllProfileIds()}, сами обязанности
     * читает вызывающий через {@see \App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface::findForProfiles()}).
     * Принимает поля фильтра (а не весь dashboard-фильтр), чтобы переиспользоваться и списком актов.
     */
    public function restrictProfileIds(StringCollection $profileIds, StringCollection $positionIds, ?string $q): StringCollection
    {
        if (!$this->access->isManager()) {
            /** @var GetProfileByUserUlidQueryResult $result */
            $result = $this->queryBus->execute(new GetProfileByUserUlidQuery($this->access->currentUserId()));
            $ownProfileId = $result->profile?->id;

            return new StringCollection(...(null !== $ownProfileId ? [$ownProfileId] : []));
        }

        if ($profileIds->count() > 0) {
            return $profileIds; // выбранные люди (чипы) / deeplink из уведомления
        }

        if ($positionIds->count() > 0 || (null !== $q && '' !== trim($q))) {
            /** @var GetProfileIdsByFilterQueryResult $result */
            $result = $this->queryBus->execute(new GetProfileIdsByFilterQuery($positionIds, $q));

            return $result->profileIds;
        }

        return new StringCollection(...$this->profiles->findAllProfileIds());
    }

    /**
     * @param list<ObligationProjection> $rows
     *
     * @return array<string, ProfileDTO>
     */
    public function profilesById(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (ObligationProjection $r): string => $r->profileId(), $rows)));
        /** @var GetProfilesByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery(new StringCollection(...$ids)));

        $byId = [];
        foreach ($result->profiles as $profile) {
            $byId[$profile->id] = $profile;
        }

        return $byId;
    }

    /**
     * Строки проекции (много обязанностей на человека) → по профилю, для построчной сборки
     * {@see \App\Compliance\Application\DTO\Dashboard\PersonRowDTO} в обоих читателях дашборда (overview/список).
     *
     * @param list<ObligationProjection> $rows
     *
     * @return array<string, list<ObligationProjection>>
     */
    public function groupByProfile(array $rows): array
    {
        $byProfile = [];
        foreach ($rows as $row) {
            $byProfile[$row->profileId()][] = $row;
        }

        return $byProfile;
    }
}
