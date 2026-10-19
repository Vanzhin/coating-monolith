<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Event\FulfillmentRecordRemoved;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\QuantifiedItem;
use App\Compliance\Domain\ValueObject\ObligationKey;
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
 * личные исключения `excludedKeys` + личные (off-norm) декларации {@see PersonalObligation}. Норму (что положено)
 * агрегат НЕ материализует — она живёт только в {@see Requirement}: держать её копию здесь означало бы два
 * источника правды, где инвариант судит по производной, отстающей от живой нормы (Вариант B). Write-инварианты
 * (выдача/списание) принимают актуальный `Requirement` параметром и резолвят норму из него на месте; read-модель
 * (строки по требованию, даты, статус) материализует отдельный проектор в `compliance_obligation_projection`.
 */
class ProfileCompliance extends Aggregate
{
    private readonly Uuid $id;
    private string $profileId;
    /** @var Collection<int, FulfillmentRecord> */
    private Collection $records;
    private StringCollection $excludedKeys;
    /** @var list<PersonalObligation> */
    private array $personalObligations;
    private ?string $cardNumber = null;
    private int $version = 1;

    public function __construct(Uuid $id, string $profileId)
    {
        $this->id = $id;
        $this->profileId = $profileId;
        $this->records = new ArrayCollection();
        $this->excludedKeys = new StringCollection();
        $this->personalObligations = [];
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

    /** @return FulfillmentRecord[] */
    public function getRecords(): array
    {
        return $this->records->toArray();
    }

    /**
     * Персональные (off-norm) декларации требования — заведены прямо на карточке, норма их не несёт.
     *
     * @return list<PersonalObligation>
     */
    public function personalObligations(): array
    {
        return $this->personalObligations;
    }

    /**
     * Факты выдачи, относящиеся к требованию (принадлежность по ключу обязанности определяет домен —
     * {@see ObligationKey::keyBelongsToRequirement}). Нужен представлению (карточка «Списать»), чтобы
     * не лезть в формат ключа снаружи.
     *
     * @return list<FulfillmentRecord>
     */
    public function recordsForRequirement(string $requirementId): array
    {
        $result = [];
        foreach ($this->records as $record) {
            if (ObligationKey::keyBelongsToRequirement($record->obligationKey(), $requirementId)) {
                $result[] = $record;
            }
        }

        return $result;
    }

    /**
     * Факты требования, годные к списанию (наполнение корзины — «Списать выбранные/всё» с карточки выдачи):
     * списанию подлежат только инвентарные (MaterialItem) позиции — структурно, по типу позиции, не по режиму
     * (режима больше нет: периодическая позиция, бывший расходник/инструктаж, остаток не ведёт и списанию не
     * подлежит никогда, независимо от выбора клиента — источник истины здесь, не на фронте). Дополнительно
     * нужно: есть что списывать ({@see FulfillmentRecord::heldAmount} > 0) и, при явном выборе позиций,
     * её ключ среди запрошенных.
     *
     * @param ?list<string> $obligationKeys null = все инвентарные held>0 позиции требования («Списать всё»)
     *
     * @return list<FulfillmentRecord>
     */
    public function writeOffEligibleFactsForRequirement(Requirement $requirement, ?array $obligationKeys): array
    {
        $inventoryKeys = $this->inventoryKeys($requirement);
        $wantedKeys = null === $obligationKeys ? null : array_fill_keys($obligationKeys, true);

        $eligible = [];
        foreach ($this->recordsForRequirement($requirement->getId()) as $fact) {
            $isHeld = $fact->heldAmount() > 0.0;
            $isInventory = isset($inventoryKeys[$fact->obligationKey()]);
            $isRequested = null === $wantedKeys || isset($wantedKeys[$fact->obligationKey()]);
            if ($isHeld && $isInventory && $isRequested) {
                $eligible[] = $fact;
            }
        }

        return $eligible;
    }

    /**
     * Ключи обязанностей требования, подлежащих списанию — инвентарные (MaterialItem) позиции нормы +
     * персональные (off-norm) декларации материального типа (они режима/типа-позиции не несут, но физически
     * те же СИЗ на руках — списываются наравне с норменными). Периодическая позиция нормы (бывший расходник/
     * инструктаж) и персональная декларация любого другого типа в этот набор не попадают НИКОГДА — это и есть
     * «списание структурно только инвентарь».
     *
     * @return array<string, true>
     */
    private function inventoryKeys(Requirement $requirement): array
    {
        $keys = [];
        foreach ($requirement->getItems() as $item) {
            if ($item instanceof MaterialItem) {
                $keys[ObligationKey::keyOf($requirement->getId(), $item->id())] = true;
            }
        }
        foreach ($this->personalObligations as $personal) {
            if ($personal->requirementId === $requirement->getId() && ComplianceType::Material === $personal->type) {
                $keys[ObligationKey::keyOf($personal->requirementId, $personal->itemId)] = true;
            }
        }

        return $keys;
    }

    /**
     * Резолв обязанности по ключу из ЖИВОЙ нормы требования (itemId — суффикс ключа после `|`) либо, если
     * нормой не покрыта, из персональных деклараций. Ни там, ни там — обязанности не существует (чужой
     * ключ/исключена/требование, из нормы которого позиция уже убрана).
     *
     * @return array{string, ComplianceType, Cadence, ?Quantity}|null [label, type, cadence, норма]
     */
    private function obligationSpecOf(Requirement $requirement, string $key): ?array
    {
        if (!ObligationKey::keyBelongsToRequirement($key, $requirement->getId())) {
            return null;
        }
        $itemId = substr($key, strlen($requirement->getId()) + 1);

        foreach ($requirement->getItems() as $item) {
            if ($item->id() === $itemId) {
                // QuantifiedItem — обе количественные позиции (материальная/периодическая, сиблинги по
                // RequirementItemInterface); MaterialItem тут ложно отрицателен для периодической нормы.
                return [$item->label(), $item->type(), $item->cadence(), $item instanceof QuantifiedItem ? $item->quantity() : null];
            }
        }
        foreach ($this->personalObligations as $personal) {
            if ($personal->requirementId === $requirement->getId() && $personal->itemId === $itemId) {
                return [$personal->label, $personal->type, $personal->cadence, $personal->quantity];
            }
        }

        return null;
    }

    /**
     * Инвариант выдачи. Единицу измерения сверяем для ОБОИХ количественных типов (материальная/периодическая) —
     * нельзя выдать «10 шт.» против нормы «10 пар», ни там, ни там. «На руках + выдаётся не меньше нормы»
     * (нельзя оставить ниже нормы) требуем ТОЛЬКО у инвентаря (Material): периодическая позиция остаток не
     * ведёт, недовыдача для неё допустима — подсвечивается красным через
     * {@see \App\Compliance\Domain\Service\ComplianceBucketResolver}, но саму выдачу не блокирует. Норма позиции
     * резолвится из `$requirement` (норменная) либо из персональной декларации. Без мутаций — можно звать до
     * промоута скана.
     *
     * @param IssuanceLine[] $lines
     */
    public function assertIssuable(Requirement $requirement, array $lines): void
    {
        foreach ($lines as $line) {
            $spec = $this->obligationSpecOf($requirement, $line->obligationKey);
            if (null === $spec) {
                continue;
            }
            [$label, $type, $cadence, $norm] = $spec;
            // Срок «по документам изготовителя» вводится при выдаче — без него позиция была бы вечно без срока.
            if (CadenceKind::ByManufacturerDoc === $cadence->kind && null === $line->manualDueDate) {
                throw new AppException(sprintf('Укажите срок окончания для позиции «%s».', $label));
            }
            if (null === $norm) {
                continue;
            }
            // Единица выдачи обязана совпадать с единицей обязанности (норма из требования / персональная позиция) —
            // нельзя выдать «10 шт.» против нормы «10 пар». Единицу норм-позиции на форме не меняют (это UX),
            // но источник истины — здесь: прямой POST/API с чужой единицей упрётся в этот инвариант. Для ОБОИХ
            // количественных типов — у периодической это та же защита (напр. мыло «мл» вместо «шт.»).
            if (null !== $line->quantity && $line->quantity->unit !== $norm->unit) {
                throw new AppException(sprintf('Единица измерения по позиции «%s» должна быть «%s».', $label, $norm->unit->title()));
            }
            if (ComplianceType::Material !== $type) {
                continue; // недовыдача периодической — не блокируется здесь (остаток не ведётся, см. докблок)
            }
            $issued = $line->quantity->amount ?? 0.0;
            if ($this->heldOf($line->obligationKey) + $issued < $norm->amount) {
                throw new AppException(sprintf('По позиции «%s» на руках с учётом выдачи меньше нормы (%s).', $label, $norm->label()));
            }
        }
    }

    /**
     * Удалить одну запись журнала (факт) НЕ завязываясь на акт выдачи — источник факта может быть прямой
     * дозаписью ({@see recordIssuance}) или актом, это не важно. Акт списания живёт отдельным кросс-персон
     * агрегатом ({@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}), поэтому каскад снятия порций с
     * его черновиков отсюда не делаем — эмитим доменное событие {@see FulfillmentRecordRemoved}, его ловит
     * {@see \App\Compliance\Application\Event\DetachRemovedRecordFromWriteOffsHandler} и отцепляет запись от
     * черновиков через репозиторий. Возвращает requirementId (для пересчёта проекции по событию).
     */
    public function removeIssuanceRecord(Uuid $recordId): string
    {
        $record = $this->recordById((string) $recordId) ?? throw new AppException('Запись журнала не найдена.');
        $key = $record->obligationKey();
        $requirementId = $this->requirementIdOfKey($key);

        $this->records->removeElement($record);
        $this->pruneOrphanPersonalObligations($requirementId);
        $this->raise(new FulfillmentRecordRemoved($this->profileId, (string) $recordId));

        return $requirementId;
    }

    /**
     * requirementId факта по ключу его обязанности — детерминированно, через обратную операцию к
     * {@see ObligationKey::keyOf} ({@see ObligationKey::requirementIdOf}). Проекцию/документы не
     * смотрим: факт может пережить исключение своей обязанности ({@see excludeObligation}) и не иметь
     * backing-документа (прямая дозапись {@see recordIssuance}) — требование от этого не перестаёт существовать.
     */
    private function requirementIdOfKey(string $key): string
    {
        return ObligationKey::requirementIdOf($key);
    }

    /**
     * Убрать персональные декларации требования, под которыми не осталось НИ ОДНОГО факта. Нужно после удаления
     * факта: позицию вне нормы, которую завели и затем сняли, иначе осталась бы фантомом (проектор её не трогает).
     */
    private function pruneOrphanPersonalObligations(string $requirementId): void
    {
        $this->personalObligations = array_values(array_filter(
            $this->personalObligations,
            fn (PersonalObligation $personal): bool => $personal->requirementId !== $requirementId
                || $this->hasRecordForKey(ObligationKey::keyOf($personal->requirementId, $personal->itemId)),
        ));
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

    /**
     * Человекочитаемое наименование обязанности по ключу (для документов/write-path, напр. снимок строки акта
     * списания): позиция живой нормы требования либо персональная декларация; нет ни там, ни там — сам ключ.
     */
    public function obligationLabelOf(Requirement $requirement, string $key): string
    {
        return $this->obligationSpecOf($requirement, $key)[0] ?? $key;
    }

    public function getExcludedKeys(): StringCollection
    {
        return $this->excludedKeys;
    }

    /** Личное отклонение: позиция требования исключена из учёта этого человека (напр. освобождён приказом). */
    public function excludeObligation(string $key): void
    {
        if (!in_array($key, $this->excludedKeys->getList(), true)) {
            $this->excludedKeys = new StringCollection(...[...$this->excludedKeys->getList(), $key]);
        }
    }

    /**
     * Персональная позиция (вне нормы) требования: заведена явной кнопкой на карточке, не из `Requirement`, но
     * трекается наравне. Дедуп по `lower(label)` среди персональных того же требования: та же позиция уже есть →
     * переиспользуем её itemId (кандидат игнорируется), иначе добавляем декларацию с переданным itemId.
     * id/itemId генерит вызывающий (как с черновиком списания). Возвращает ключ обязанности
     * (`ObligationKey::keyOf(requirementId, itemId)`).
     */
    public function addPersonalObligation(string $itemId, string $requirementId, string $label, ComplianceType $type, Cadence $cadence, ?Quantity $quantity): string
    {
        $normalizedLabel = mb_strtolower(trim($label));
        foreach ($this->personalObligations as $existing) {
            if ($existing->requirementId === $requirementId && mb_strtolower(trim($existing->label)) === $normalizedLabel) {
                return ObligationKey::keyOf($existing->requirementId, $existing->itemId);
            }
        }

        $this->personalObligations[] = new PersonalObligation($itemId, $requirementId, trim($label), $type, $cadence, $quantity);

        return ObligationKey::keyOf($requirementId, $itemId);
    }

    /** Зафиксировать выдачу/прохождение факта напрямую в журнал (без промежуточного акта-черновика). */
    public function recordFulfillment(
        Uuid $recordId,
        string $obligationKey,
        \DateTimeImmutable $fulfilledAt,
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
    }

    /**
     * Прямая дозапись в журнал (без акта-черновика): каждая строка выдачи становится фактом сразу, с
     * `documentId=null` — значит сразу выдана ({@see isIssued}), в held входит немедленно. Инвариант выдачи
     * (по материальным позициям «на руках + выдача» не меньше нормы) — {@see assertIssuable}. Даты/held/active —
     * время-независимые поля read-модели, их считает проектор, не агрегат (Вариант B).
     */
    public function recordIssuance(Requirement $requirement, IssuanceLine ...$lines): void
    {
        $this->assertHasCardNumber();
        $this->assertIssuable($requirement, $lines);
        foreach ($lines as $line) {
            $this->records->add(new FulfillmentRecord(
                $line->recordId, $this, $line->obligationKey, $line->fulfilledAt,
                $line->quantity, $line->wearPercent, $line->note, $line->manualDueDate,
                instructionDetails: $line->instructionDetails,
            ));
        }
    }

    /**
     * Прямая дозапись в журнал инструктажей: по каждой строке сперва проверяется доменный инвариант полноты
     * полей схемы вида журнала требования ({@see JournalKind::assertDetailsComplete}) — неполная карточка
     * инструктажа не попадает в журнал, — затем делегирует в {@see recordIssuance}.
     */
    public function recordInstruction(Requirement $requirement, IssuanceLine ...$lines): void
    {
        foreach ($lines as $line) {
            $requirement->getJournalKind()?->assertDetailsComplete($line->instructionDetails);
        }
        $this->recordIssuance($requirement, ...$lines);
    }

    /** Инвариант выдачи: без № карточки факт в журнал не пишется — карточка СИЗ обязана иметь номер. */
    private function assertHasCardNumber(): void
    {
        if (null === $this->cardNumber) {
            throw new AppException('Укажите № карточки, без него выдачу записать нельзя.');
        }
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}
