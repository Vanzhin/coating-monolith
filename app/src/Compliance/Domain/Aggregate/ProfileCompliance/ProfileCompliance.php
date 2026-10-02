<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
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
     * Проверка «выдано не меньше нормы» по материальным позициям — без мутаций (можно звать до промоута скана).
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
            if (ComplianceType::Material === $obligation->type() && null !== $norm
                && (null === $line->quantity || $line->quantity->amount < $norm->amount)) {
                throw new AppException(sprintf('По позиции «%s» выдано меньше нормы (%s).', $obligation->label(), $norm->label()));
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

    /**
     * Положить материальные позиции в корзину (черновик акта списания): есть открытый черновик — туда, нет
     * или оформлен — создаём новый. Позиция (item = факт выдачи) «переезжает» в акт через {@see FulfillmentRecord::placeInWriteOffAct()}.
     * ЭФФЕКТА НЕТ: факты не гасим, пересчёта нет — он наступает при оформлении акта ({@see signWriteOffAct}).
     * Списывать можно только из действующей карточки.
     *
     * @param list<string> $obligationKeys
     */
    public function writeOff(Uuid $candidateActId, string $requirementId, array $obligationKeys, \DateTimeImmutable $now): void
    {
        if ([] === $this->signedDocumentsFor($requirementId)) {
            throw new AppException('Списать можно только из действующей карточки — черновик не списывается.');
        }
        $act = $this->openWriteOffDraftFor($requirementId);
        $isNew = null === $act;
        if (null === $act) {
            $act = new WriteOffAct($candidateActId, $this, $requirementId, $now);
            $this->writeOffActs->add($act);
        }
        $added = 0;
        foreach ($obligationKeys as $key) {
            $obligation = $this->obligationByKey($key);
            if (null === $obligation || ComplianceType::Material !== $obligation->type()) {
                continue; // в корзину кладём только материальные позиции
            }
            $fact = $this->currentFactOfKey($key);
            if (null === $fact || null !== $fact->writeOffActId()) {
                continue; // нет текущей выдачи или позиция уже в корзине
            }
            $act->assertMutable();
            $fact->placeInWriteOffAct($act->getId());
            ++$added;
        }
        if ($added > 0) {
            $act->touch($now);
        } elseif ($isNew) {
            $this->writeOffActs->removeElement($act); // ничего не легло — пустой акт не держим
        }
    }

    /**
     * Позиции (item-факты) корзины акта списания.
     *
     * @return list<FulfillmentRecord>
     */
    public function itemsOfWriteOffAct(string $actId): array
    {
        $items = [];
        foreach ($this->records as $record) {
            if ($record->isInWriteOffAct($actId)) {
                $items[] = $record;
            }
        }

        return $items;
    }

    /** Убрать позицию из корзины (откат, пока акт — черновик); опустевший акт удаляем. */
    public function cancelWriteOffItem(string $actId, string $recordId, \DateTimeImmutable $now): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->assertMutable();
        $fact = $this->recordById($recordId);
        if (null !== $fact && $fact->isInWriteOffAct($actId)) {
            $fact->removeFromWriteOffAct();
        }
        if ([] === $this->itemsOfWriteOffAct($actId)) {
            $this->writeOffActs->removeElement($act);

            return;
        }
        $act->touch($now);
    }

    /**
     * Проставить причины позициям корзины (на странице акта списания).
     *
     * @param array<string, WriteOffReason> $reasonByRecordId
     */
    public function applyWriteOffReasons(string $actId, array $reasonByRecordId, \DateTimeImmutable $now): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->assertMutable();
        foreach ($this->itemsOfWriteOffAct($actId) as $fact) {
            if (isset($reasonByRecordId[$fact->getId()])) {
                $fact->setWriteOffReason($reasonByRecordId[$fact->getId()]);
            }
        }
        $act->touch($now);
    }

    /**
     * Оформить акт списания: причины заданы на все позиции + комиссия + №/дата + скан → заморожен. ТОЛЬКО ТУТ
     * наступает эффект — гасим списанные факты (на дату акта) и пересчитываем позиции (освобождаются → новый
     * черновик выдачи заведёт сервис формирования в Application).
     */
    public function signWriteOffAct(string $actId, WriteOffCommission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now, ObligationDueCalculator $calculator): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $items = $this->itemsOfWriteOffAct($actId);
        foreach ($items as $fact) {
            if (null === $fact->writeOffReason()) {
                $label = $this->obligationByKey($fact->obligationKey())?->label() ?? $fact->obligationKey();
                throw new AppException(sprintf('Укажите причину списания для позиции «%s».', $label));
            }
        }
        $act->sign($commission, $actNumber, $actDate, $scanFileId, $now);
        foreach ($items as $fact) {
            if ($fact->isReturned()) {
                continue;
            }
            $fact->markReturned($actDate, null, $fact->writeOffReason()?->title());
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

    /** Текущая (последняя не-списанная) выдача позиции — её и списываем. */
    private function currentFactOfKey(string $obligationKey): ?FulfillmentRecord
    {
        $latest = null;
        foreach ($this->records as $record) {
            if ($record->obligationKey() !== $obligationKey || $record->isReturned()) {
                continue;
            }
            if (null === $latest || $record->fulfilledAt() > $latest->fulfilledAt()) {
                $latest = $record;
            }
        }

        return $latest;
    }

    private function recordById(string $recordId): ?FulfillmentRecord
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
        ?\DateTimeImmutable $returnedAt = null,
        ?Quantity $returnedQuantity = null,
        ?string $documentId = null,
    ): void {
        $this->records->add(new FulfillmentRecord(
            $recordId, $this, $obligationKey, $fulfilledAt,
            $quantity, $wearPercent, $note, $manualDueDate, $returnedAt, $returnedQuantity, $documentId,
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
            if ($record->obligationKey() !== $key || $record->isReturned()) {
                continue; // списанная выдача не считается «текущей» → позиция освобождается
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
