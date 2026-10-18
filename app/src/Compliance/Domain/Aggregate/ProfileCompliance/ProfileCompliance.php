<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Event\FulfillmentRecordRemoved;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\JournalKind;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;
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
    private StringCollection $excludedKeys;
    private ?string $cardNumber = null;
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

    /** № карточки СИЗ — свойство журнала, одна карточка на человека. Нет записей выдачи → ещё не закреплён. */
    public function cardNumber(): ?string
    {
        return $this->cardNumber;
    }

    /**
     * Закрепить № карточки СИЗ — ровно один раз. Повторная попытка (номер уже закреплён) — no-op: случайный
     * другой POST не должен затереть уже выданную карточку. Пустая строка номером не считается.
     */
    public function assignCardNumber(string $number): void
    {
        $number = trim($number);
        if ('' === $number || null !== $this->cardNumber) {
            return;
        }
        $this->cardNumber = $number;
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

    /**
     * Факты требования, годные к списанию (наполнение корзины — «Списать выбранные/всё» с карточки выдачи):
     * есть что списывать ({@see FulfillmentRecord::heldAmount} > 0), позиция не расходная (ДСИЗ — списанию не
     * подлежит никогда, независимо от выбора клиента — источник истины здесь, не на фронте) и, при явном
     * выборе позиций, её ключ среди запрошенных. Нематериальные факты отсекаются уже условием held>0 — у
     * них количества нет, `heldAmount()` всегда 0.
     *
     * @param ?list<string> $obligationKeys null = все инвентарные held>0 позиции требования («Списать всё»)
     *
     * @return list<FulfillmentRecord>
     */
    public function writeOffEligibleFactsForRequirement(string $requirementId, ?array $obligationKeys): array
    {
        $consumableKeys = $this->consumableObligationKeys();
        $wantedKeys = null === $obligationKeys ? null : array_fill_keys($obligationKeys, true);

        $eligible = [];
        foreach ($this->recordsForRequirement($requirementId) as $fact) {
            $isHeld = $fact->heldAmount() > 0.0;
            $isConsumable = isset($consumableKeys[$fact->obligationKey()]);
            $isRequested = null === $wantedKeys || isset($wantedKeys[$fact->obligationKey()]);
            if ($isHeld && !$isConsumable && $isRequested) {
                $eligible[] = $fact;
            }
        }

        return $eligible;
    }

    /**
     * Ключи обязанностей в режиме «расходная» (ДСИЗ) — списанию не подлежат никогда. Нет режима (напр.
     * персональная позиция вне нормы) — трактуем как инвентарь, в множество не включаем.
     *
     * @return array<string, true>
     */
    private function consumableObligationKeys(): array
    {
        $keys = [];
        foreach ($this->obligations as $obligation) {
            if ($obligation->isConsumable()) {
                $keys[$obligation->key()] = true;
            }
        }

        return $keys;
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

    /**
     * Удалить одну запись журнала (факт) НЕ завязываясь на акт выдачи — источник факта может быть прямой
     * дозаписью ({@see recordIssuance}) или актом, это не важно. Акт списания живёт отдельным кросс-персон
     * агрегатом ({@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}), поэтому каскад снятия порций с
     * его черновиков отсюда не делаем — эмитим доменное событие {@see FulfillmentRecordRemoved}, его ловит
     * {@see \App\Compliance\Application\Event\DetachRemovedRecordFromWriteOffsHandler} и отцепляет запись от
     * черновиков через репозиторий. Возвращает requirementId (для пересчёта по событию).
     */
    public function removeIssuanceRecord(Uuid $recordId, ObligationDueCalculator $calculator): string
    {
        $record = $this->recordById((string) $recordId) ?? throw new AppException('Запись журнала не найдена.');
        $key = $record->obligationKey();
        $requirementId = $this->requirementIdOfKey($key);

        $this->records->removeElement($record);
        $this->recomputeObligation($key, $calculator);
        $this->pruneOrphanPersonalObligations($requirementId);
        $this->raise(new FulfillmentRecordRemoved($this->profileId, (string) $recordId));

        return $requirementId;
    }

    /**
     * requirementId факта по ключу его обязанности — детерминированно, через обратную операцию к
     * {@see TrackedObligation::keyOf} ({@see TrackedObligation::requirementIdOf}). Проекцию/документы не
     * смотрим: факт может пережить исключение своей обязанности ({@see excludeObligation}) и не иметь
     * backing-документа (прямая дозапись {@see recordIssuance}) — требование от этого не перестаёт существовать.
     */
    private function requirementIdOfKey(string $key): string
    {
        return TrackedObligation::requirementIdOf($key);
    }

    /**
     * Убрать персональные (origin=Personal) обязанности требования, под которыми не осталось НИ ОДНОГО факта.
     * Нужно после удаления факта: позицию вне нормы, которую завели и затем сняли, иначе осталась бы фантомом
     * (пересборка проекции её не трогает).
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

    /** На руках по позиции = Σ heldAmount по её выданным фактам журнала. */
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
     * Любая запись журнала — выданный факт (входит в held/сроки): актов-документов больше нет, выдача идёт
     * прямо в журнал ({@see recordIssuance}). Метод оставлен явной точкой смысла «факт выдан».
     */
    private function isIssued(FulfillmentRecord $record): bool
    {
        return true; // прямой журнал — черновиков-корзин и актов нет, факт всегда выдан
    }

    /**
     * Есть ли по обязанности хотя бы один выданный факт ({@see isIssued}) — именно это включает трекинг сроков
     * обязанности (`active`), не привязываясь к какому-либо акту-документу.
     */
    public function hasIssuedFactFor(string $obligationKey): bool
    {
        foreach ($this->records as $record) {
            if ($record->obligationKey() === $obligationKey && $this->isIssued($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Применить эффект акта списания (кросс-персон, {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}):
     * по каждой своей строке акта гасит количество на факте ({@see FulfillmentRecord::addReturnedQuantity}).
     * Акт зовёт этот метод на каждом затронутом `ProfileCompliance` отдельно (оркестрация — в
     * Application-хендлере оформления акта), поэтому recordId, которого нет в своём журнале — не наш факт,
     * тихо пропускаем.
     *
     * @param array<string, float> $recordIdToQty
     */
    public function applyWriteOff(array $recordIdToQty): void
    {
        foreach ($recordIdToQty as $recordId => $qty) {
            $this->recordById((string) $recordId)?->addReturnedQuantity($qty);
        }
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
     * Нет обязанностей требования → нельзя добавить (карточка без нормы). Дедуп по `lower(label)` среди
     * персональных того же требования: та же позиция уже есть → переиспользуем её itemId (кандидаты игнорим),
     * иначе создаём с переданным кандидатом. id/itemId генерит вызывающий (как с черновиком списания).
     * Возвращает ключ обязанности (`keyOf(requirementId, itemId)`).
     */
    public function addPersonalObligation(Uuid $candidateId, string $candidateItemId, string $requirementId, string $label, Cadence $cadence, ?Quantity $quantity): string
    {
        $normalizedLabel = mb_strtolower(trim($label));
        $reference = null;
        foreach ($this->obligations as $obligation) {
            if (!TrackedObligation::keyBelongsToRequirement($obligation->key(), $requirementId)) {
                continue;
            }
            $reference ??= $obligation;
            if (TrackedObligation::ORIGIN_PERSONAL === $obligation->origin() && mb_strtolower(trim($obligation->label())) === $normalizedLabel) {
                return $obligation->key();
            }
        }
        if (null === $reference) {
            throw new AppException('Нельзя добавить позицию в карточку без нормы по требованию.');
        }

        $this->putObligation(new TrackedObligation(
            $candidateId, $this, $requirementId, $candidateItemId, $reference->requirementName(),
            trim($label), $reference->type(), $cadence, $quantity, $reference->departmentId(),
            TrackedObligation::ORIGIN_PERSONAL,
        ));

        return TrackedObligation::keyOf($requirementId, $candidateItemId);
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

    /**
     * Прямая дозапись в журнал (без акта-черновика): каждая строка выдачи становится фактом сразу, с
     * `documentId=null` — значит сразу выдана ({@see isIssued}), в held и даты входит немедленно. Инвариант
     * выдачи (по материальным позициям «на руках + выдача» не меньше нормы) — {@see assertIssuable}.
     */
    public function recordIssuance(ObligationDueCalculator $calculator, IssuanceLine ...$lines): void
    {
        $this->assertHasCardNumber();
        $this->assertIssuable($lines);
        foreach ($lines as $line) {
            $this->records->add(new FulfillmentRecord(
                $line->recordId, $this, $line->obligationKey, $line->fulfilledAt,
                $line->quantity, $line->wearPercent, $line->note, $line->manualDueDate,
                instructionDetails: $line->instructionDetails,
            ));
        }
        foreach ($this->distinctKeys($lines) as $key) {
            $this->recomputeObligation($key, $calculator);
        }
    }

    /**
     * Прямая дозапись в журнал инструктажей: по каждой строке сперва проверяется доменный инвариант полноты
     * полей схемы вида журнала ({@see JournalKind::assertDetailsComplete}) — неполная карточка инструктажа не
     * попадает в журнал, — затем делегирует в {@see recordIssuance}.
     */
    public function recordInstruction(ObligationDueCalculator $calculator, JournalKind $kind, IssuanceLine ...$lines): void
    {
        foreach ($lines as $line) {
            $kind->assertDetailsComplete($line->instructionDetails);
        }
        $this->recordIssuance($calculator, ...$lines);
    }

    /** Инвариант выдачи: без № карточки факт в журнал не пишется — карточка СИЗ обязана иметь номер. */
    private function assertHasCardNumber(): void
    {
        if (null === $this->cardNumber) {
            throw new AppException('Укажите № карточки, без него выдачу записать нельзя.');
        }
    }

    /**
     * Ключи обязанностей, затронутых строками выдачи (без повторов) — что пересчитывать после дозаписи.
     *
     * @param IssuanceLine[] $lines
     *
     * @return list<string>
     */
    private function distinctKeys(array $lines): array
    {
        $keys = [];
        foreach ($lines as $line) {
            $keys[$line->obligationKey] = true;
        }

        return array_keys($keys);
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

    /** Худший статус по обязанностям (для карточки/агрегата); учитывает активность (выданный факт) и сроки. */
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
        // active = есть выданный факт по позиции. Любая запись/удаление факта зовёт recomputeObligation, значит
        // светофор/даты синхронизируются сразу — без ожидания отдельной пересборки проекции (норм и personal).
        $obligation->setActive($this->hasIssuedFactFor($key));
    }
}
