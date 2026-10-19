<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\Type\ObligationOrigin;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\ObligationKey;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQuery;
use App\Personnel\Application\UseCase\Query\GetProfileIdsByPositions\GetProfileIdsByPositionsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

/**
 * ЕДИНСТВЕННЫЙ писатель read-модели `compliance_obligation_projection`. Материализует строку обязанности на
 * (profileId, requirementId, itemId) из ЖИВОЙ нормы (требования должности) + персональных деклараций + журнала
 * фактов, считая ВРЕМЯ-НЕЗАВИСИМЫЕ поля `lastFulfilledAt`/`nextDueAt` (через {@see ObligationDueCalculator})/
 * `heldQuantity`/`active`. Bucket/статус НЕ хранит — он чистая функция от строки и `now`, выводится на чтении
 * единым резолвером.
 *
 * Запись идёт DBAL-upsert'ом в таблицу ({@see ObligationProjectionRepositoryInterface::upsertMany} + orphan-delete).
 * Агрегат (Вариант B) норму больше не материализует — единственный источник нормы здесь это `Requirement`,
 * персональных (off-norm) деклараций — VO-список {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\PersonalObligation}
 * на агрегате.
 */
final readonly class ComplianceObligationProjector
{
    public function __construct(
        private ObligationProjectionRepositoryInterface $projection,
        private RequirementRepositoryInterface $requirements,
        private ProfileComplianceRepositoryInterface $repository,
        private QueryBusInterface $queryBus,
        private ObligationDueCalculator $calculator,
    ) {
    }

    /**
     * Пересобрать строки проекции одного профиля. $requirementId=null → полная (все требования должности); иначе
     * точечная — трогаем только срез этого требования (горячий путь факта зовёт с конкретным требованием).
     */
    public function projectProfile(string $profileId, ?string $requirementId = null): void
    {
        $this->projectProfileSlice($profileId, null === $requirementId ? null : new StringCollection($requirementId));
    }

    /**
     * Bulk-пересборка (замена {@see ComplianceProjectionRebuilder::rebuild} по целям). Фильтры комбинируются,
     * все пусты → всё. Множество профилей = явные + по должностям + (если заданы ТОЛЬКО требования) покрытые ими.
     */
    public function projectMany(?StringCollection $requirementIds, ?StringCollection $positionIds, ?StringCollection $profileIds): void
    {
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
            $targets = $this->repository->findAllProfileIds();
        }

        $scope = [] !== $reqIds ? new StringCollection(...$reqIds) : null;
        foreach (array_values(array_unique($targets)) as $profileId) {
            $this->projectProfileSlice($profileId, $scope);
        }
    }

    /**
     * Пересборка строк одного профиля (опц. среза требований): desired-vs-facts (норменные позиции
     * `Requirement::getItems()` + персональные {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\PersonalObligation})
     * против журнала фактов, запись — upsert + orphan-delete.
     *
     * $onlyRequirementIds null → полная пересборка профиля
     */
    private function projectProfileSlice(string $profileId, ?StringCollection $onlyRequirementIds): void
    {
        $profileCompliance = $this->repository->findByProfile($profileId);

        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $result->profile) {
            return; // профиль недоступен — проекцию не трогаем (как и пересборщик)
        }
        $departmentId = $result->profile->departmentId;
        $positionId = $result->profile->positionId;

        $records = null !== $profileCompliance ? $profileCompliance->getRecords() : [];
        $personalObligations = null !== $profileCompliance ? $profileCompliance->personalObligations() : [];
        $excluded = null !== $profileCompliance ? $profileCompliance->getExcludedKeys()->getList() : [];

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

        /** @var array<string, array<string, mixed>> $rows key => строка для upsert */
        $rows = [];
        /** @var array<string, list<string>> $keptItemIds requirementId => itemId[] (для orphan-delete) */
        $keptItemIds = [];
        /** @var array<string, string> $requirementNames requirementId => name (норменные уже загружены; для персональных — лениво) */
        $requirementNames = [];

        // 1. Норменные строки живой нормы.
        foreach ($requirements as $requirement) {
            $requirementNames[$requirement->getId()] = $requirement->getName();
            foreach ($requirement->getItems() as $item) {
                $key = ObligationKey::keyOf($requirement->getId(), $item->id());
                if (in_array($key, $excluded, true)) {
                    continue;
                }
                $rows[$key] = $this->buildRow(
                    $profileId, $requirement->getId(), $item->id(), $requirement->getName(), $item->label(),
                    $item->type(), $item->cadence(), $item instanceof MaterialItem ? $item->quantity() : null,
                    $departmentId, ObligationOrigin::Norm->value, $item instanceof MaterialItem ? $item->mode() : null,
                    $records, $key,
                );
                $keptItemIds[$requirement->getId()][] = $item->id();
            }
        }

        // 2. Персональные декларации (off-norm, из VO агрегата) — держим всегда, норма их не несёт.
        foreach ($personalObligations as $personal) {
            $key = ObligationKey::keyOf($personal->requirementId, $personal->itemId);
            if (isset($rows[$key])) {
                continue; // уже покрыта живой нормой (не должно происходить, но защитно)
            }
            if (null !== $onlyRequirementIds && !$this->keyInAnyRequirement($key, $onlyRequirementIds->getList())) {
                continue; // точечная пересборка чужие требования не трогает
            }
            $requirementName = $requirementNames[$personal->requirementId]
                ??= $this->requirements->findOneById($personal->requirementId)?->getName() ?? '';
            $rows[$key] = $this->buildRow(
                $profileId, $personal->requirementId, $personal->itemId, $requirementName,
                $personal->label, $personal->type, $personal->cadence, $personal->quantity,
                $departmentId, ObligationOrigin::Personal->value, null, $records, $key,
            );
            $keptItemIds[$personal->requirementId][] = $personal->itemId;
        }

        // Orphan-чистка по пересобранным требованиям: точечно — только заданные; полностью — объединение требований
        // живой нормы и уже лежащих строк профиля (чтобы снять строки требований, целиком ушедших из нормы).
        $requirementIdsToClean = null !== $onlyRequirementIds
            ? $onlyRequirementIds->getList()
            : $this->requirementIdsHavingRows($profileId, array_keys($keptItemIds));

        $this->projection->upsertMany(array_values($rows));

        foreach (array_values(array_unique($requirementIdsToClean)) as $requirementId) {
            $this->projection->deleteObsoleteForProfileRequirement(
                $profileId, $requirementId, new StringCollection(...($keptItemIds[$requirementId] ?? [])),
            );
        }
    }

    /**
     * Строка проекции под upsert: метаданные + ВРЕМЯ-НЕЗАВИСИМЫЕ поля из журнала фактов. Ключи — имена колонок
     * (snake_case), значения — готовые скаляры (даты `Y-m-d H:i:s`/null, enum→value).
     *
     * @param FulfillmentRecord[] $records
     *
     * @return array<string, mixed>
     */
    private function buildRow(
        string $profileId,
        string $requirementId,
        string $itemId,
        string $requirementName,
        string $label,
        ComplianceType $type,
        Cadence $cadence,
        ?Quantity $quantity,
        string $departmentId,
        string $origin,
        ?MaterialMode $mode,
        array $records,
        string $key,
    ): array {
        [$lastFulfilledAt, $nextDueAt, $held, $active] = $this->computeFromFacts($records, $key, $cadence, $type);

        return [
            'id' => (string) Uuid::v7(), // на ON CONFLICT существующий id сохраняется (в SET не входит)
            'profile_id' => $profileId,
            'requirement_id' => $requirementId,
            'item_id' => $itemId,
            'requirement_name' => $requirementName,
            'label' => $label,
            'type' => $type->value,
            'cadence' => $cadence->label(),
            // Структурная периодичность (kind+число+единица) — JSONB
            // ({@see \App\Compliance\Infrastructure\Database\DBAL\CadenceType}). Нужна форме выдачи для
            // ПЕРЕСЧЁТА (предпросмотр «след. выдача»), не только показа готового label-снимка выше.
            'cadence_struct' => json_encode($cadence->jsonSerialize(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'quantity' => $quantity?->amount,
            'quantity_unit' => $quantity?->unit->value,
            'department_id' => $departmentId,
            'last_fulfilled_at' => $lastFulfilledAt?->format('Y-m-d H:i:s'),
            'next_due_at' => $nextDueAt?->format('Y-m-d H:i:s'),
            'held_quantity' => $held,
            'active' => $active,
            'origin' => $origin,
            'mode' => $mode?->value,
        ];
    }

    /**
     * Время-независимые поля из журнала фактов позиции: lastFulfilledAt = дата последнего НЕ истощённого факта,
     * nextDueAt — через {@see ObligationDueCalculator}, active = есть хотя бы один факт по позиции (любая запись
     * журнала выдана). `held` зависит от типа обязанности: инвентарь (материальная) — Σ на руках по всем фактам
     * (накопительно, списания уменьшают); периодическая — количество ТОЛЬКО самой свежей выдачи (доввыдача не
     * копится, старые факты не складываются с новыми — "последняя выдача", а не "сумма за всё время").
     *
     * @param FulfillmentRecord[] $records
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable, 2: float, 3: bool}
     */
    private function computeFromFacts(array $records, string $key, Cadence $cadence, ComplianceType $type): array
    {
        $latest = null;
        $held = 0.0;
        $hasFact = false;
        foreach ($records as $record) {
            if ($record->obligationKey() !== $key) {
                continue;
            }
            $hasFact = true;
            $held += $record->heldAmount();
            $isCurrent = !$record->isDepleted(); // материальный истощён → не текущий; нематериальный всегда текущий
            if ($isCurrent && (null === $latest || $record->fulfilledAt() > $latest->fulfilledAt())) {
                $latest = $record;
            }
        }

        if (ComplianceType::Periodic === $type) {
            // Периодическая не накапливает: held = количество самой свежей (по fulfilledAt) выдачи, не сумма.
            $held = $latest?->quantity()->amount ?? 0.0;
        }

        $lastFulfilledAt = $latest?->fulfilledAt();
        $nextDueAt = $this->calculator->nextDue($cadence, $lastFulfilledAt, $latest?->manualDueDate());

        return [$lastFulfilledAt, $nextDueAt, $held, $hasFact];
    }

    /** @param list<string> $requirementIds */
    private function keyInAnyRequirement(string $key, array $requirementIds): bool
    {
        foreach ($requirementIds as $requirementId) {
            if (ObligationKey::keyBelongsToRequirement($key, $requirementId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Требования, по которым у профиля есть/должны быть строки (для полной orphan-чистки): пересобранные сейчас +
     * уже лежащие в проекции (их требования, целиком ушедшие из нормы, тоже надо подчистить).
     *
     * @param list<string> $keptRequirementIds
     *
     * @return list<string>
     */
    private function requirementIdsHavingRows(string $profileId, array $keptRequirementIds): array
    {
        $ids = $keptRequirementIds;
        foreach ($this->projection->findForProfiles(new StringCollection($profileId)) as $row) {
            $ids[] = $row->requirementId();
        }

        return array_values(array_unique($ids));
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
