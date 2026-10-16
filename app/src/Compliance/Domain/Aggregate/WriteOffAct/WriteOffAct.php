<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\WriteOffAct;

use App\Compliance\Domain\Type\DocumentStatus;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Uid\Uuid;

/**
 * Акт списания СИЗ — самостоятельный документ, кросс-персон: один акт покрывает позиции сразу нескольких
 * профилей ({@see WriteOffActLine::profileId()}), в отличие от старого акта внутри `ProfileCompliance`.
 * Цикл {@see DocumentStatus}: Черновик (наполняем/правим строки, задаём причины) → Подписан (комиссия + №/дата +
 * скан, заморожен). Эффект подписи (гашение количества на фактах выдачи) НЕ применяется здесь — это оркеструет
 * Application-хендлер, которому известны затронутые `ProfileCompliance`.
 */
class WriteOffAct
{
    private readonly Uuid $id;
    private string $requirementId;
    private string $organizationId;
    private DocumentStatus $status;
    /**
     * Doctrine one-to-many требует `Collection`, не `array`, — иначе падает при flush
     * (`PersistentCollection` не ложится в типизированное `array`-свойство).
     *
     * @var Collection<int, WriteOffActLine>
     */
    private Collection $lines;
    private ?Commission $commission = null;
    private ?string $actNumber = null;
    private ?\DateTimeImmutable $actDate = null;
    private ?string $orderNumber = null;
    private ?\DateTimeImmutable $orderDate = null;
    private ?string $representativePosition = null;
    private ?string $representativeFio = null;
    private ?string $scanFileId = null;
    private ?\DateTimeImmutable $signedAt = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;
    private int $version = 1; // Doctrine optimistic lock — только читаем, не мутируем сами

    public function __construct(Uuid $id, string $requirementId, string $organizationId, \DateTimeImmutable $now)
    {
        $this->id = $id;
        $this->requirementId = $requirementId;
        $this->organizationId = $organizationId;
        $this->status = DocumentStatus::Formed;
        $this->lines = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Добавить строки конкретного профиля (только черновик). Дедуп по `recordId` в пределах ВСЕГО акта — один
     * факт выдачи не может встретиться в акте дважды, даже если его пытаются добавить повторно под тем же профилем.
     *
     * @param list<array{recordId: string, labelSnapshot: string, quantity: float}> $lines
     */
    public function addLinesForProfile(string $profileId, array $lines): void
    {
        $this->assertMutable();
        $existingRecordIds = array_map(static fn (WriteOffActLine $line): string => $line->recordId(), $this->lines->toArray());

        // Собираем в локальный массив и мутируем состояние акта ТОЛЬКО после того, как все строки батча
        // успешно сконструировались — иначе строка с quantity<=0 в середине батча оставляет акт с частью
        // уже добавленных строк (несогласованное состояние до персиста).
        $toAdd = [];
        foreach ($lines as $line) {
            if (in_array($line['recordId'], $existingRecordIds, true)) {
                continue; // дубль по recordId — тихо пропускаем
            }
            $newLine = new WriteOffActLine(Uuid::v7(), $profileId, $line['recordId'], $line['labelSnapshot'], $line['quantity'], null, $this);
            $toAdd[] = $newLine;
            $existingRecordIds[] = $newLine->recordId();
        }

        foreach ($toAdd as $newLine) {
            $this->lines->add($newLine);
        }
    }

    /**
     * Полностью заменить состав порций (set-семантика правки, как редактирование карточки): каждая строка →
     * {количество + причина}; чего нет в списке, выпадает. `profileId`/`labelSnapshot` берём у уже существующей
     * в акте строки с тем же `recordId` — этот метод не умеет заводить строки для новых профилей/фактов, это
     * задача {@see addLinesForProfile}. Только пока черновик.
     *
     * @param list<array{recordId: string, quantity: float, reason: ?WriteOffReason}> $lines
     */
    public function replacePortions(array $lines): void
    {
        $this->assertMutable();
        $existingByRecordId = [];
        foreach ($this->lines as $line) {
            $existingByRecordId[$line->recordId()] = $line;
        }

        $replaced = [];
        foreach ($lines as $line) {
            $existing = $existingByRecordId[$line['recordId']] ?? null;
            if (null === $existing) {
                throw new AppException(sprintf('Позиция %s не входит в акт списания.', $line['recordId']));
            }
            // Новой строке — свежий id, не id старой. Старая уходит в orphan-removal; если присвоить ей
            // id старой строки, повторный persist (после findById/findOpenDraft, когда старая строка ещё
            // managed в identity map) кидает EntityIdentityCollisionException.
            $replaced[] = new WriteOffActLine(
                Uuid::v7(),
                $existing->profileId(),
                $existing->recordId(),
                $existing->labelSnapshot(),
                $line['quantity'],
                $line['reason'] ?? null,
                $this,
            );
        }

        $this->lines->clear();
        foreach ($replaced as $newLine) {
            $this->lines->add($newLine);
        }
    }

    /**
     * Удалить строки, относящиеся к указанным фактам выдачи (каскад при удалении факта выдачи). Только черновик:
     * подписанный акт заморожен — защита инвариантом, не только SQL-фильтром выборки черновиков в репозитории.
     *
     * @param list<string> $recordIds
     */
    public function removeLinesByRecordIds(array $recordIds): void
    {
        $this->assertMutable();
        $toRemove = array_fill_keys($recordIds, true);
        foreach ($this->lines->toArray() as $line) {
            if (isset($toRemove[$line->recordId()])) {
                $this->lines->removeElement($line);
            }
        }
    }

    /**
     * Сохранить реквизиты черновика (№/дата акта, приказ, представитель, комиссия), пока не подписан. Пустые
     * допустимы — это черновик, не финал.
     */
    public function saveDetails(
        string $actNumber,
        ?\DateTimeImmutable $actDate,
        Commission $commission,
        string $orderNumber,
        ?\DateTimeImmutable $orderDate,
        string $representativePosition,
        string $representativeFio,
        \DateTimeImmutable $now,
    ): void {
        $this->assertMutable();
        $this->actNumber = '' === trim($actNumber) ? null : trim($actNumber);
        $this->actDate = $actDate;
        $this->commission = $commission->isEmpty() ? null : $commission;
        $this->orderNumber = '' === trim($orderNumber) ? null : trim($orderNumber);
        $this->orderDate = $orderDate;
        $this->representativePosition = '' === trim($representativePosition) ? null : trim($representativePosition);
        $this->representativeFio = '' === trim($representativeFio) ? null : trim($representativeFio);
        $this->updatedAt = $now;
    }

    /** Подписать акт: причины на всех строках, непустая комиссия, приложен скан. НЕ применяет списание к фактам. */
    public function sign(Commission $commission, string $actNumber, \DateTimeImmutable $actDate, string $scanFileId, \DateTimeImmutable $now): void
    {
        $this->assertMutable();
        $this->assertReasonsComplete();
        if ($commission->isEmpty()) {
            throw new AppException('Укажите хотя бы одного члена комиссии.');
        }
        if ('' === trim($scanFileId)) {
            throw new AppException('Приложите скан подписанного акта списания.');
        }
        $this->commission = $commission;
        $this->actNumber = trim($actNumber);
        $this->actDate = $actDate;
        $this->scanFileId = $scanFileId;
        $this->status = DocumentStatus::Signed;
        $this->signedAt = $now;
        $this->updatedAt = $now;
    }

    /** @return array<string, list<WriteOffActLine>> Строки, сгруппированные по профилю (порядок первого появления). */
    public function linesByProfile(): array
    {
        $grouped = [];
        foreach ($this->lines as $line) {
            $grouped[$line->profileId()][] = $line;
        }

        return $grouped;
    }

    /** @return list<string> Профили, затронутые актом (порядок первого появления). */
    public function profileIds(): array
    {
        return array_keys($this->linesByProfile());
    }

    /** @return list<WriteOffActLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    public function isDraft(): bool
    {
        return DocumentStatus::Formed === $this->status;
    }

    public function isSigned(): bool
    {
        return DocumentStatus::Signed === $this->status;
    }

    public function assertMutable(): void
    {
        if (!$this->isDraft()) {
            throw new AppException('Акт списания подписан — его нельзя изменить или удалить.');
        }
    }

    private function assertReasonsComplete(): void
    {
        foreach ($this->lines as $line) {
            if (null === $line->reason()) {
                throw new AppException('Укажите причину списания для всех позиций акта.');
            }
        }
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function requirementId(): string
    {
        return $this->requirementId;
    }

    public function organizationId(): string
    {
        return $this->organizationId;
    }

    public function status(): DocumentStatus
    {
        return $this->status;
    }

    public function commission(): ?Commission
    {
        return $this->commission;
    }

    public function actNumber(): ?string
    {
        return $this->actNumber;
    }

    public function actDate(): ?\DateTimeImmutable
    {
        return $this->actDate;
    }

    public function orderNumber(): ?string
    {
        return $this->orderNumber;
    }

    public function orderDate(): ?\DateTimeImmutable
    {
        return $this->orderDate;
    }

    public function representativePosition(): ?string
    {
        return $this->representativePosition;
    }

    public function representativeFio(): ?string
    {
        return $this->representativeFio;
    }

    public function scanFileId(): ?string
    {
        return $this->scanFileId;
    }

    public function signedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
