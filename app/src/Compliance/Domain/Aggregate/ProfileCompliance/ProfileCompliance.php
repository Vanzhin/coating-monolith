<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Exception\AppException;
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
    /** @var Collection<int, RequirementDocument> */
    private Collection $documents;
    /** @var Collection<int, WriteOffAct> */
    private Collection $writeOffActs;
    private StringCollection $excludedKeys;
    private int $version = 1;

    public function __construct(Uuid $id, string $profileId)
    {
        $this->id = $id;
        $this->profileId = $profileId;
        $this->obligations = new ArrayCollection();
        $this->records = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->writeOffActs = new ArrayCollection();
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

    /**
     * Факты выдачи, относящиеся к требованию (принадлежность по ключу обязанности определяет домен —
     * {@see TrackedObligation::keyBelongsToRequirement}). Нужен представлению (карточка «Списать»), чтобы
     * не лезть в формат ключа снаружи.
     *
     * @return list<FulfillmentRecord>
     */
    public function recordsForRequirement(string $requirementId): array
    {
        $result = [];
        foreach ($this->records as $record) {
            if (TrackedObligation::keyBelongsToRequirement($record->obligationKey(), $requirementId)) {
                $result[] = $record;
            }
        }

        return $result;
    }

    /** @return RequirementDocument[] */
    public function getDocuments(): array
    {
        return $this->documents->toArray();
    }

    /** @return WriteOffAct[] */
    public function getWriteOffActs(): array
    {
        return $this->writeOffActs->toArray();
    }

    /** Открытый черновик акта по требованию (инвариант: не более одного). */
    public function openDraftFor(string $requirementId): ?RequirementDocument
    {
        foreach ($this->documents as $document) {
            if ($document->requirementId() === $requirementId && $document->isDraft()) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Подписанные акты по требованию (история выдач-артефактов со сканами).
     *
     * @return list<RequirementDocument>
     */
    public function signedDocumentsFor(string $requirementId): array
    {
        $signed = [];
        foreach ($this->documents as $document) {
            if ($document->requirementId() === $requirementId && $document->isSigned()) {
                $signed[] = $document;
            }
        }

        return $signed;
    }

    /**
     * Инвариант выдачи: по материальным позициям «на руках + выдаётся» не меньше нормы (нельзя оставить ниже
     * нормы). Без мутаций — можно звать до промоута скана.
     *
     * @param IssuanceLine[] $lines
     */
    public function assertIssuable(array $lines): void
    {
        foreach ($lines as $line) {
            $obligation = $this->obligationByKey($line->obligationKey);
            if (null === $obligation) {
                continue;
            }
            $norm = $obligation->quantity();
            if (ComplianceType::Material !== $obligation->type() || null === $norm) {
                continue;
            }
            $issued = $line->quantity->amount ?? 0.0;
            if ($this->heldOf($line->obligationKey) + $issued < $norm->amount) {
                throw new AppException(sprintf('По позиции «%s» на руках с учётом выдачи меньше нормы (%s).', $obligation->label(), $norm->label()));
            }
        }
    }

    /** Завести черновик акта по требованию (маркер «карточка нужна»). Открытый черновик уже есть → {@see AppException}. */
    public function formDraft(Uuid $id, string $requirementId, \DateTimeImmutable $now): void
    {
        if (null !== $this->openDraftFor($requirementId)) {
            throw new AppException('По требованию уже есть открытый черновик — оформите или удалите его.');
        }
        $this->documents->add(new RequirementDocument($id, $this, $requirementId, $now));
    }

    /**
     * Оформить черновик: приложить подписанный скан и заморозить акт. Строки выдачи (из формы) становятся
     * фактами, трекинг сроков требования включается.
     *
     * @param IssuanceLine[] $lines
     */
    public function signDraft(string $documentId, ?string $scanFileId, array $lines, ObligationDueCalculator $calculator, \DateTimeImmutable $now): void
    {
        $document = $this->documentById($documentId) ?? throw new AppException('Черновик не найден.');
        $this->recordAndSign($document, $lines, $scanFileId, $calculator, $now);
    }

    /** Удалить черновик (подписанный акт не удаляется). */
    public function deleteDraft(string $documentId): void
    {
        $document = $this->documentById($documentId);
        if (null === $document) {
            return;
        }
        $document->assertMutable();
        $this->documents->removeElement($document);
    }

    /** Открытый черновик акта списания по требованию (корзина; инвариант: не более одного). */
    public function openWriteOffDraftFor(string $requirementId): ?WriteOffAct
    {
        foreach ($this->writeOffActs as $act) {
            if ($act->requirementId() === $requirementId && $act->isDraft()) {
                return $act;
            }
        }

        return null;
    }

    /** На руках по позиции = Σ heldAmount по её фактам. */
    public function heldOf(string $obligationKey): float
    {
        $sum = 0.0;
        foreach ($this->records as $record) {
            if ($record->obligationKey() === $obligationKey) {
                $sum += $record->heldAmount();
            }
        }

        return $sum;
    }

    /**
     * Открыть черновик акта списания по требованию, создав пустой, если открытого нет (кнопка «Перейти к акту
     * списания» на действующей карточке). Списывать можно только из действующей карточки. Возвращает id акта.
     */
    public function startOrGetWriteOffDraft(Uuid $candidateActId, string $requirementId, \DateTimeImmutable $now): string
    {
        if ([] === $this->signedDocumentsFor($requirementId)) {
            throw new AppException('Списать можно только из действующей карточки — черновик не списывается.');
        }
        $act = $this->openWriteOffDraftFor($requirementId);
        if (null === $act) {
            $act = new WriteOffAct($candidateActId, $this, $requirementId, $now);
            $this->writeOffActs->add($act);
        }

        return $act->getId();
    }

    /**
     * Сохранить состав акта списания (на его странице, пока черновик): каждая строка — факт + количество к
     * списанию + причина. Set-семантика: заменяет весь состав. Количество не больше, чем на руках по факту.
     * Эффекта нет — он при оформлении акта ({@see signWriteOffAct}).
     *
     * @param list<array{recordId: string, quantity: float, reason: ?WriteOffReason}> $lines
     */
    public function saveWriteOffAct(string $actId, array $lines, \DateTimeImmutable $now): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $portions = [];
        foreach ($lines as $line) {
            $quantity = (float) $line['quantity'];
            if ($quantity <= 0.0) {
                continue;
            }
            $fact = $this->recordById((string) $line['recordId']);
            if (null === $fact) {
                continue;
            }
            if ($quantity > $fact->heldAmount()) {
                throw new AppException(sprintf('Нельзя списать больше, чем на руках (%s).', $this->obligationLabelOf($fact->obligationKey())));
            }
            // Причина в черновике может быть пустой (позиция ещё не списана); обязательна при оформлении — {@see WriteOffAct::sign()}.
            $portions[] = ['recordId' => $fact->getId(), 'quantity' => $quantity, 'reason' => $line['reason'] ?? null];
        }
        $act->replacePortions($portions, $now);
    }

    /** @return list<WriteOffItem> */
    public function itemsOfWriteOffAct(string $actId): array
    {
        $act = $this->writeOffActById($actId);

        return null === $act ? [] : $act->items();
    }

    /** Удалить черновик акта списания (оформленный не удаляется). */
    public function deleteWriteOffDraft(string $actId): void
    {
        $act = $this->writeOffActById($actId);
        if (null === $act) {
            return;
        }
        $act->assertMutable();
        $this->writeOffActs->removeElement($act);
    }

    /** Оформить акт списания (комиссия+№/дата+скан): замораживает и гасит количество на фактах + пересчёт. */
    public function signWriteOffAct(string $actId, Commission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now, ObligationDueCalculator $calculator): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->sign($commission, $actNumber, $actDate, $scanFileId, $now);
        foreach ($act->items() as $portion) {
            $fact = $this->recordById($portion->recordId());
            if (null === $fact) {
                continue;
            }
            $fact->addReturnedQuantity($portion->quantity());
            $this->recomputeObligation($fact->obligationKey(), $calculator);
        }
    }

    /**
     * Общая запись-и-подпись: ИНВАРИАНТ — скан приложен И по материальным позициям выдано не меньше нормы
     * ({@see assertIssuable}); строки → факты {@see FulfillmentRecord}; акт подписан; трекинг сроков включён.
     *
     * @param IssuanceLine[] $lines
     */
    private function recordAndSign(RequirementDocument $document, array $lines, ?string $scanFileId, ObligationDueCalculator $calculator, \DateTimeImmutable $now): void
    {
        if (null === $scanFileId || '' === trim($scanFileId)) {
            throw new AppException('Приложите скан подписанной карточки — без него нельзя оформить.');
        }
        $this->assertIssuable($lines);
        foreach ($lines as $line) {
            $this->recordFulfillment(
                $line->recordId, $line->obligationKey, $line->fulfilledAt, $calculator,
                $line->quantity, $line->wearPercent, null, $line->manualDueDate,
                documentId: $document->getId(),
            );
        }
        $document->markSigned($scanFileId, $now);
        $this->setActiveForRequirement($document->requirementId(), true);
    }

    private function documentById(string $documentId): ?RequirementDocument
    {
        foreach ($this->documents as $document) {
            if ($document->getId() === $documentId) {
                return $document;
            }
        }

        return null;
    }

    /** Факт выдачи по id (нужен проектору акта списания — наименование/единица/дата тянутся с факта порции). */
    public function recordById(string $recordId): ?FulfillmentRecord
    {
        foreach ($this->records as $record) {
            if ($record->getId() === $recordId) {
                return $record;
            }
        }

        return null;
    }

    private function writeOffActById(string $actId): ?WriteOffAct
    {
        foreach ($this->writeOffActs as $act) {
            if ($act->getId() === $actId) {
                return $act;
            }
        }

        return null;
    }

    /** Человекочитаемое наименование обязанности по ключу (для документов/UI); нет в проекции — вернём ключ. */
    public function obligationLabelOf(string $key): string
    {
        return $this->obligationByKey($key)?->label() ?? $key;
    }

    private function obligationByKey(string $key): ?TrackedObligation
    {
        foreach ($this->obligations as $obligation) {
            if ($obligation->key() === $key) {
                return $obligation;
            }
        }

        return null;
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
        ?\DateTimeImmutable $manualDueDate = null,
        ?string $documentId = null,
    ): void {
        $this->records->add(new FulfillmentRecord(
            $recordId, $this, $obligationKey, $fulfilledAt,
            $quantity, $wearPercent, $note, $manualDueDate, documentId: $documentId,
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
                $resolver->statusFor(
                    $obligation->isActive(),
                    $obligation->lastFulfilledAt(),
                    $obligation->nextDueAt(),
                    $now,
                    $obligation->quantity()?->amount,
                    $obligation->heldQuantity(),
                ),
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

    /** Пересчитать даты/остатки только по обязанностям одного требования (точечная пересборка нормы). */
    public function recomputeRequirement(string $requirementId, ObligationDueCalculator $calculator): void
    {
        foreach ($this->obligations as $obligation) {
            if (TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
                $this->recomputeObligation($obligation->key(), $calculator);
            }
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
        $held = 0.0;
        foreach ($this->records as $record) {
            if ($record->obligationKey() !== $key) {
                continue;
            }
            $held += $record->heldAmount();
            $current = !$record->isDepleted(); // материальный истощён → не текущий; нематериальный всегда текущий
            if ($current && (null === $latest || $record->fulfilledAt() > $latest->fulfilledAt())) {
                $latest = $record;
            }
        }

        $lastFulfilledAt = $latest?->fulfilledAt();
        $nextDueAt = $calculator->nextDue($obligation->cadence(), $lastFulfilledAt, $latest?->manualDueDate());
        $obligation->setDates($lastFulfilledAt, $nextDueAt);
        $obligation->setHeldQuantity($held);
    }
}
