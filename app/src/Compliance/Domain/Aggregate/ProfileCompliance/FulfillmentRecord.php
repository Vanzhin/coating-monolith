<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Symfony\Component\Uid\Uuid;

/**
 * Факт выдачи — ИСТОЧНИК ИСТИНЫ и количественный item: `quantity` выдано, `returnedQuantity` списано суммарно
 * (аккумулятор), «на руках по факту» = разница. `documentId` — акт выдачи (провенанс «откуда»). Привязан к
 * обязанности по `obligationKey` (requirementId|label). Материальная часть (кол-во/износ) nullable: у процедуры
 * её нет — такой факт в held не участвует и не «истощается». `manualDueDate` — дата для «по документам изготовителя».
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
    private float $returnedQuantity;
    private ?string $documentId;

    public function __construct(
        Uuid $id,
        ProfileCompliance $profileCompliance,
        string $obligationKey,
        \DateTimeImmutable $fulfilledAt,
        ?Quantity $quantity = null,
        ?Percent $wearPercent = null,
        ?string $note = null,
        ?\DateTimeImmutable $manualDueDate = null,
        float $returnedQuantity = 0.0,
        ?string $documentId = null,
    ) {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->obligationKey = $obligationKey;
        $this->fulfilledAt = $fulfilledAt;
        $this->quantity = $quantity;
        $this->wearPercent = null === $wearPercent?->value() ? null : (float) $wearPercent->value();
        $this->note = $note;
        $this->manualDueDate = $manualDueDate;
        $this->returnedQuantity = $returnedQuantity;
        $this->documentId = $documentId;
    }

    /** Списать количество (возврат): накапливается, не превышая выданного. */
    public function addReturnedQuantity(float $amount): void
    {
        $max = $this->quantity->amount ?? 0.0;
        $this->returnedQuantity = min($max, $this->returnedQuantity + max(0.0, $amount));
    }

    /** На руках по факту = выдано − списано (у нематериального — 0, held к нему неприменим). */
    public function heldAmount(): float
    {
        if (null === $this->quantity) {
            return 0.0;
        }

        return max(0.0, $this->quantity->amount - $this->returnedQuantity);
    }

    /** Материальный факт списан полностью (на руках 0). Нематериальный не истощается. */
    public function isDepleted(): bool
    {
        return null !== $this->quantity && $this->returnedQuantity >= $this->quantity->amount;
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

    public function returnedQuantity(): float
    {
        return $this->returnedQuantity;
    }

    public function documentId(): ?string
    {
        return $this->documentId;
    }
}
