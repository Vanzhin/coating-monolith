<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\WriteOffReason;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Symfony\Component\Uid\Uuid;

/**
 * Факт выдачи/прохождения — ИСТОЧНИК ИСТИНЫ (проекция производна от фактов+нормы) и «item», который гуляет из
 * акта в акт: `documentId` — акт получения (откуда выдан), `writeOffActId` — акт списания (куда переехал, null
 * пока не списан) + причина `writeOffReason`. Привязан к обязанности по `obligationKey` (requirementId|label),
 * который переживает пересборку проекции. Материальная часть (кол-во/износ/возврат) — nullable: у процедуры её
 * нет. `manualDueDate` — конкретная дата для «по документам изготовителя». `%износа` — число [0;100] ({@see Percent}).
 */
class FulfillmentRecord
{
    private readonly Uuid $id;
    private ProfileCompliance $profileCompliance;
    private string $obligationKey;
    private \DateTimeImmutable $fulfilledAt;
    private ?Quantity $quantity;
    private ?float $wearPercent;
    private ?string $note;
    private ?\DateTimeImmutable $manualDueDate;
    private ?\DateTimeImmutable $returnedAt;
    private ?Quantity $returnedQuantity;
    /** Акт получения ({@see RequirementDocument}), которым выдана эта позиция — провенанс «откуда». */
    private ?string $documentId;
    /** Акт списания ({@see WriteOffAct}), в корзину которого позиция положена — «куда». Null, пока не списывается. */
    private ?string $writeOffActId;
    private ?WriteOffReason $writeOffReason;

    public function __construct(
        Uuid $id,
        ProfileCompliance $profileCompliance,
        string $obligationKey,
        \DateTimeImmutable $fulfilledAt,
        ?Quantity $quantity = null,
        ?Percent $wearPercent = null,
        ?string $note = null,
        ?\DateTimeImmutable $manualDueDate = null,
        ?\DateTimeImmutable $returnedAt = null,
        ?Quantity $returnedQuantity = null,
        ?string $documentId = null,
        ?string $writeOffActId = null,
        ?WriteOffReason $writeOffReason = null,
    ) {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->obligationKey = $obligationKey;
        $this->fulfilledAt = $fulfilledAt;
        $this->quantity = $quantity;
        $this->wearPercent = null === $wearPercent?->value() ? null : (float) $wearPercent->value();
        $this->note = $note;
        $this->manualDueDate = $manualDueDate;
        $this->returnedAt = $returnedAt;
        $this->returnedQuantity = $returnedQuantity;
        $this->documentId = $documentId;
        $this->writeOffActId = $writeOffActId;
        $this->writeOffReason = $writeOffReason;
    }

    /** Положить в корзину акта списания (черновик): item «переезжает» в акт, но эффекта пока нет. */
    public function placeInWriteOffAct(string $writeOffActId): void
    {
        $this->writeOffActId = $writeOffActId;
    }

    /** Откат: вынуть из акта списания (снимаем и причину). */
    public function removeFromWriteOffAct(): void
    {
        $this->writeOffActId = null;
        $this->writeOffReason = null;
    }

    public function setWriteOffReason(?WriteOffReason $reason): void
    {
        $this->writeOffReason = $reason;
    }

    public function isInWriteOffAct(string $writeOffActId): bool
    {
        return $this->writeOffActId === $writeOffActId;
    }

    /** Списать (возврат): факт перестаёт считаться «текущей выдачей» в пересчёте обязанности. */
    public function markReturned(\DateTimeImmutable $returnedAt, ?Quantity $returnedQuantity, ?string $note): void
    {
        $this->returnedAt = $returnedAt;
        $this->returnedQuantity = $returnedQuantity ?? $this->quantity; // по умолчанию — всё выданное
        if (null !== $note) {
            $this->note = $note;
        }
    }

    public function isReturned(): bool
    {
        return null !== $this->returnedAt;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function profileCompliance(): ProfileCompliance
    {
        return $this->profileCompliance;
    }

    public function obligationKey(): string
    {
        return $this->obligationKey;
    }

    public function fulfilledAt(): \DateTimeImmutable
    {
        return $this->fulfilledAt;
    }

    public function quantity(): ?Quantity
    {
        return $this->quantity;
    }

    public function wearPercent(): ?Percent
    {
        return null === $this->wearPercent ? null : new Percent($this->wearPercent);
    }

    public function note(): ?string
    {
        return $this->note;
    }

    public function manualDueDate(): ?\DateTimeImmutable
    {
        return $this->manualDueDate;
    }

    public function returnedAt(): ?\DateTimeImmutable
    {
        return $this->returnedAt;
    }

    public function returnedQuantity(): ?Quantity
    {
        return $this->returnedQuantity;
    }

    public function documentId(): ?string
    {
        return $this->documentId;
    }

    public function writeOffActId(): ?string
    {
        return $this->writeOffActId;
    }

    public function writeOffReason(): ?WriteOffReason
    {
        return $this->writeOffReason;
    }
}
