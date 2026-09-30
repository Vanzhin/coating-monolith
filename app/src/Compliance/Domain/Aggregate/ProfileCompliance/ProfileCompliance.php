<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

/**
 * Учёт по человеку (один на `profileId`). Держит журнал фактов `FulfillmentRecord` (источник истины) +
 * проекцию `TrackedObligation` (производная от нормы+фактов, пересобирается сервисом в Д4) + личные
 * исключения `excludedKeys`. Даты обязанностей пересчитываются при изменении фактов доменным сервисом
 * {@see ObligationDueCalculator}. Статус — не хранится, выводится {@see ComplianceStatusResolver}.
 */
class ProfileCompliance extends Aggregate
{
    private readonly Uuid $id;
    private string $profileId;
    /** @var Collection<int, TrackedObligation> */
    private Collection $obligations;
    /** @var Collection<int, FulfillmentRecord> */
    private Collection $records;
    private StringCollection $excludedKeys;
    private int $version = 1;

    public function __construct(Uuid $id, string $profileId)
    {
        $this->id = $id;
        $this->profileId = $profileId;
        $this->obligations = new ArrayCollection();
        $this->records = new ArrayCollection();
        $this->excludedKeys = new StringCollection();
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getProfileId(): string
    {
        return $this->profileId;
    }

    /** @return TrackedObligation[] */
    public function getObligations(): array
    {
        return $this->obligations->toArray();
    }

    /** @return FulfillmentRecord[] */
    public function getRecords(): array
    {
        return $this->records->toArray();
    }

    public function getExcludedKeys(): StringCollection
    {
        return $this->excludedKeys;
    }

    /** Добавить/заменить строку проекции по её ключу (вызывает пересборщик из Д4). */
    public function putObligation(TrackedObligation $obligation): void
    {
        foreach ($this->obligations as $existing) {
            if ($existing->key() === $obligation->key()) {
                $this->obligations->removeElement($existing);
                break;
            }
        }
        $this->obligations->add($obligation);
    }

    public function removeObligationByKey(string $key): void
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation->key() === $key) {
                $this->obligations->removeElement($obligation);

                return;
            }
        }
    }

    public function excludeObligation(string $key): void
    {
        if (!in_array($key, $this->excludedKeys->getList(), true)) {
            $this->excludedKeys = new StringCollection(...[...$this->excludedKeys->getList(), $key]);
        }
        $this->removeObligationByKey($key);
    }

    /** Зафиксировать выдачу/прохождение и пересчитать даты затронутой обязанности. */
    public function recordFulfillment(
        Uuid $recordId,
        string $obligationKey,
        \DateTimeImmutable $fulfilledAt,
        ObligationDueCalculator $calculator,
        ?Quantity $quantity = null,
        ?Percent $wearPercent = null,
        ?string $note = null,
        ?string $fileId = null,
        ?\DateTimeImmutable $manualDueDate = null,
        ?\DateTimeImmutable $returnedAt = null,
        ?Quantity $returnedQuantity = null,
    ): void {
        $this->records->add(new FulfillmentRecord(
            $recordId, $this, $obligationKey, $fulfilledAt,
            $quantity, $wearPercent, $note, $fileId, $manualDueDate, $returnedAt, $returnedQuantity,
        ));
        $this->recomputeObligation($obligationKey, $calculator);
    }

    public function removeRecord(string $recordId, ObligationDueCalculator $calculator): void
    {
        foreach ($this->records as $record) {
            if ($record->getId() === $recordId) {
                $key = $record->obligationKey();
                $this->records->removeElement($record);
                $this->recomputeObligation($key, $calculator);

                return;
            }
        }
    }

    /** Документ требования подписан/расподписан — включает/выключает трекинг сроков у его обязанностей. */
    public function setActiveForRequirement(string $requirementId, bool $active): void
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation->requirementId() === $requirementId) {
                $obligation->setActive($active);
            }
        }
    }

    /** Худший статус по обязанностям (для карточки/агрегата); учитывает подпись и сроки. */
    public function worstStatus(ComplianceStatusResolver $resolver, ?ComplianceType $filter, \DateTimeImmutable $now): ComplianceStatus
    {
        $worst = ComplianceStatus::Green;
        foreach ($this->obligations as $obligation) {
            if (null !== $filter && $obligation->type() !== $filter) {
                continue;
            }
            $worst = ComplianceStatus::worseOf(
                $worst,
                $resolver->statusFor($obligation->isActive(), $obligation->lastFulfilledAt(), $obligation->nextDueAt(), $now),
            );
        }

        return $worst;
    }

    /** Пересчитать даты всех обязанностей из фактов (после пересборки проекции). */
    public function recomputeAll(ObligationDueCalculator $calculator): void
    {
        foreach ($this->obligations as $obligation) {
            $this->recomputeObligation($obligation->key(), $calculator);
        }
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    private function recomputeObligation(string $key, ObligationDueCalculator $calculator): void
    {
        $obligation = null;
        foreach ($this->obligations as $candidate) {
            if ($candidate->key() === $key) {
                $obligation = $candidate;
                break;
            }
        }
        if (null === $obligation) {
            return; // факт по позиции вне текущей проекции (напр. вне нормы) — даты не считаем
        }

        $latest = null;
        foreach ($this->records as $record) {
            if ($record->obligationKey() !== $key) {
                continue;
            }
            if (null === $latest || $record->fulfilledAt() > $latest->fulfilledAt()) {
                $latest = $record;
            }
        }

        $lastFulfilledAt = $latest?->fulfilledAt();
        $nextDueAt = $calculator->nextDue($obligation->cadence(), $lastFulfilledAt, $latest?->manualDueDate());
        $obligation->setDates($lastFulfilledAt, $nextDueAt);
    }
}
