<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\Cadence;
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
            // Срок «по документам изготовителя» вводится при выдаче — без него позиция была бы вечно без срока.
            if (CadenceKind::ByManufacturerDoc === $obligation->cadence()->kind && null === $line->manualDueDate) {
                throw new AppException(sprintf('Укажите срок окончания для позиции «%s».', $obligation->label()));
            }
            $norm = $obligation->quantity();
            if (ComplianceType::Material !== $obligation->type() || null === $norm) {
                continue;
            }
            // Единица выдачи обязана совпадать с единицей обязанности (норма из требования / персональная позиция) —
            // нельзя выдать «10 шт.» против нормы «10 пар». Единицу норм-позиции на форме не меняют (это UX),
            // но источник истины — здесь: прямой POST/API с чужой единицей упрётся в этот инвариант.
            if (null !== $line->quantity && $line->quantity->unit !== $norm->unit) {
                throw new AppException(sprintf('Единица измерения по позиции «%s» должна быть «%s».', $obligation->label(), $norm->unit->title()));
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
    public function signDraft(string $documentId, ?string $scanFileId, array $lines, ObligationDueCalculator $calculator, \DateTimeImmutable $now, string $actNumber, string $responsibleFio): void
    {
        $document = $this->documentById($documentId) ?? throw new AppException('Черновик не найден.');
        $this->recordAndSign($document, $lines, $scanFileId, $calculator, $now, $actNumber, $responsibleFio);
    }

    /**
     * Сохранить черновик целиком: строки корзины (замена) + реквизиты. Акт остаётся Formed — в held не входит.
     * Калькулятор не нужен: черновые записи гейт «выдано» не пускает, held/даты не меняются.
     *
     * @param IssuanceLine[] $lines
     */
    public function saveDraft(string $documentId, string $actNumber, string $responsibleFio, array $lines, \DateTimeImmutable $now): void
    {
        $document = $this->documentById($documentId) ?? throw new AppException('Черновик не найден.');
        $document->assertMutable();
        foreach ($this->records as $record) {
            if ($record->documentId() === $documentId) {
                $this->records->removeElement($record); // orphan-removal удалит строку корзины
            }
        }
        foreach ($lines as $line) {
            $this->records->add(new FulfillmentRecord(
                $line->recordId, $this, $line->obligationKey, $line->fulfilledAt,
                $line->quantity, $line->wearPercent, null, $line->manualDueDate, documentId: $documentId,
            ));
        }
        $document->saveDraftDetails($actNumber, $responsibleFio, $now);
        $this->pruneOrphanPersonalObligations($document->requirementId()); // убрали персональную строку → убрать её обязанность
    }

    /**
     * Наполнить корзину черновика дефицитом по норме требования (идемпотентно, аддитивно). По материальной
     * позиции добавляет недостающее `max(0, норма − на руках − уже в корзине)`; по нематериальной — строку
     * присутствия, если её в корзине ещё нет. Существующие строки не трогает, ничего не удаляет; №/ответственного
     * не пишет (это ввод пользователя). Повторный прогон без смены нормы ничего не добавляет.
     */
    public function topUpDraftFromNorm(string $documentId, string $requirementId, \DateTimeImmutable $now): void
    {
        $document = $this->documentById($documentId) ?? throw new AppException('Черновик не найден.');
        $document->assertMutable();
        foreach ($this->obligations as $obligation) {
            if ($obligation->requirementId() !== $requirementId) {
                continue;
            }
            $key = $obligation->key();
            $norm = $obligation->quantity();
            if (null === $norm) { // нематериальная — строка присутствия, если ещё нет
                if (!$this->cartHasKey($documentId, $key)) {
                    $this->records->add(new FulfillmentRecord(Uuid::v7(), $this, $key, $now, null, null, null, null, documentId: $documentId));
                }
                continue;
            }
            $missing = $norm->amount - $this->heldOf($key) - $this->cartSumFor($documentId, $key);
            if ($missing > 1e-9) {
                $this->records->add(new FulfillmentRecord(Uuid::v7(), $this, $key, $now, new Quantity($missing, $norm->unit), null, null, null, documentId: $documentId));
            }
        }
    }

    private function cartSumFor(string $documentId, string $obligationKey): float
    {
        $sum = 0.0;
        foreach ($this->records as $record) {
            if ($record->documentId() === $documentId && $record->obligationKey() === $obligationKey) {
                $sum += $record->quantity()->amount ?? 0.0;
            }
        }

        return $sum;
    }

    private function cartHasKey(string $documentId, string $obligationKey): bool
    {
        foreach ($this->records as $record) {
            if ($record->documentId() === $documentId && $record->obligationKey() === $obligationKey) {
                return true;
            }
        }

        return false;
    }

    /** Удалить черновик (подписанный акт не удаляется); возвращает requirementId удалённого (для пересчёта по событию) или null, если не найден. */
    public function deleteDraft(string $documentId): ?string
    {
        $document = $this->documentById($documentId);
        if (null === $document) {
            return null;
        }
        $document->assertMutable();
        $requirementId = $document->requirementId();
        foreach ($this->records as $record) {
            if ($record->documentId() === $documentId) {
                $this->records->removeElement($record); // корзина уходит вместе с черновиком
            }
        }
        $this->documents->removeElement($document);
        $this->pruneOrphanPersonalObligations($requirementId); // персональные позиции без факта не висят фантомом

        return $requirementId;
    }

    /**
     * Убрать персональные (origin=Personal) обязанности требования, под которыми не осталось НИ ОДНОГО факта
     * (ни в корзине, ни подписанного). Нужно после удаления/пере-сохранения черновика: позиция вне нормы,
     * которую завели и затем сняли, иначе осталась бы фантомом (пересборка проекции её не трогает).
     */
    private function pruneOrphanPersonalObligations(string $requirementId): void
    {
        $toRemove = [];
        foreach ($this->obligations as $obligation) {
            if (TrackedObligation::ORIGIN_PERSONAL !== $obligation->origin()
                || !TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
                continue;
            }
            if (!$this->hasRecordForKey($obligation->key())) {
                $toRemove[] = $obligation->key();
            }
        }
        foreach ($toRemove as $key) {
            $this->removeObligationByKey($key);
        }
    }

    private function hasRecordForKey(string $obligationKey): bool
    {
        foreach ($this->records as $record) {
            if ($record->obligationKey() === $obligationKey) {
                return true;
            }
        }

        return false;
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

    /** На руках по позиции = Σ heldAmount по её ВЫДАННЫМ фактам (корзина черновика в held не входит). */
    public function heldOf(string $obligationKey): float
    {
        $sum = 0.0;
        foreach ($this->records as $record) {
            if ($record->obligationKey() === $obligationKey && $this->isIssued($record)) {
                $sum += $record->heldAmount();
            }
        }

        return $sum;
    }

    /**
     * Факт считается выданным (входит в held/сроки), только если его акт подписан. Запись без документа —
     * прямой/исторический факт — считается выданной. Запись на черновике (Formed) — корзина, НЕ выдано.
     */
    private function isIssued(FulfillmentRecord $record): bool
    {
        $documentId = $record->documentId();
        if (null === $documentId || '' === $documentId) {
            return true;
        }
        $document = $this->documentById($documentId);

        return null === $document || $document->isSigned();
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
    public function saveWriteOffAct(
        string $actId,
        array $lines,
        \DateTimeImmutable $now,
        string $actNumber = '',
        ?\DateTimeImmutable $actDate = null,
        ?Commission $commission = null,
        string $orderNumber = '',
        ?\DateTimeImmutable $orderDate = null,
        string $representativePosition = '',
        string $representativeFio = '',
    ): void {
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
        // № / дата акта + приказ + представитель + комиссия на черновике (без подписи)
        $act->saveDraftDetails($actNumber, $actDate, $commission ?? new Commission(), $orderNumber, $orderDate, $representativePosition, $representativeFio, $now);
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
    /**
     * Синхронная часть оформления акта: заморозить акт и погасить количество на фактах (источник истины, ровно
     * один раз — повтор невозможен, акт уже подписан). Производное — пересчёт проекции и черновик выдачи на
     * дефицит — делаем в воркере по событию {@see \App\Compliance\Domain\Event\WriteOffActSigned} (идемпотентно).
     */
    public function signWriteOffAct(string $actId, Commission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now): void
    {
        $act = $this->writeOffActById($actId) ?? throw new AppException('Акт списания не найден.');
        $act->sign($commission, $actNumber, $actDate, $scanFileId, $now);
        foreach ($act->items() as $portion) {
            $this->recordById($portion->recordId())?->addReturnedQuantity($portion->quantity());
        }
    }

    /**
     * Общая запись-и-подпись: ИНВАРИАНТ — скан приложен И по материальным позициям выдано не меньше нормы
     * ({@see assertIssuable}); строки → факты {@see FulfillmentRecord}; акт подписан; трекинг сроков включён.
     *
     * @param IssuanceLine[] $lines
     */
    private function recordAndSign(RequirementDocument $document, array $lines, ?string $scanFileId, ObligationDueCalculator $calculator, \DateTimeImmutable $now, string $actNumber, string $responsibleFio): void
    {
        if (null === $scanFileId || '' === trim($scanFileId)) {
            throw new AppException('Приложите скан подписанной карточки — без него нельзя оформить.');
        }
        $this->assertIssuable($lines);
        $this->saveDraft($document->getId(), $actNumber, $responsibleFio, $lines, $now); // корзина = строки формы (ещё Formed)
        $document->markSigned($scanFileId, $actNumber, $responsibleFio, $now); // сначала подпись
        $this->setActiveForRequirement($document->requirementId(), true);
        $this->recomputeRequirement($document->requirementId(), $calculator); // потом пересчёт — записи прошли гейт
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

    /** Тип обязанностей требования в карточке (мономорфен) — нужен, чтобы персональная позиция приняла тип акта. */
    public function typeOfRequirement(string $requirementId): ?ComplianceType
    {
        foreach ($this->obligations as $obligation) {
            if (TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
                return $obligation->type();
            }
        }

        return null;
    }

    /**
     * Персональная позиция (origin=Personal) в акте требования: не из нормы, но трекается наравне. Тип (= тип
     * акта, мономорфен), имя требования и отдел берём с существующей обязанности акта — клиент тип не задаёт.
     * Нет обязанностей требования → нельзя добавить (карточка без нормы). Возвращает ключ созданной обязанности.
     */
    public function addPersonalObligation(Uuid $id, string $requirementId, string $label, Cadence $cadence, ?Quantity $quantity): string
    {
        $key = TrackedObligation::keyOf($requirementId, $label);
        $reference = null;
        foreach ($this->obligations as $obligation) {
            if ($obligation->key() === $key) {
                throw new AppException(sprintf('Позиция «%s» уже есть в карточке.', trim($label)));
            }
            if (null === $reference && TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
                $reference = $obligation;
            }
        }
        if (null === $reference) {
            throw new AppException('Нельзя добавить позицию в карточку без нормы по требованию.');
        }

        $this->putObligation(new TrackedObligation(
            $id, $this, $requirementId, $reference->requirementName(),
            trim($label), $reference->type(), $cadence, $quantity, $reference->departmentId(),
            TrackedObligation::ORIGIN_PERSONAL,
        ));

        return $key;
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
            if ($record->obligationKey() !== $key || !$this->isIssued($record)) {
                continue; // чужая позиция или строка корзины (не выдано) — в held/даты не идёт
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
