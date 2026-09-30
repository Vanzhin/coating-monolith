<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Symfony\Component\Uid\Uuid;

/**
 * Факт выдачи/прохождения — ИСТОЧНИК ИСТИНЫ (проекция производна от фактов+нормы). Привязан к обязанности
 * по `obligationKey` (requirementId|label), который переживает пересборку проекции. Материальная часть
 * (кол-во/износ/возврат) — nullable: у процедуры её нет. `manualDueDate` — конкретная дата для «по
 * документам изготовителя». `%износа` храним числом [0;100] (валидируем через {@see Percent}).
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
    private ?string $fileId;
    private ?\DateTimeImmutable $manualDueDate;
    private ?\DateTimeImmutable $returnedAt;
    private ?Quantity $returnedQuantity;

    public function __construct(
        Uuid $id,
        ProfileCompliance $profileCompliance,
        string $obligationKey,
        \DateTimeImmutable $fulfilledAt,
        ?Quantity $quantity = null,
        ?Percent $wearPercent = null,
        ?string $note = null,
        ?string $fileId = null,
        ?\DateTimeImmutable $manualDueDate = null,
        ?\DateTimeImmutable $returnedAt = null,
        ?Quantity $returnedQuantity = null,
    ) {
        $this->id = $id;
        $this->profileCompliance = $profileCompliance;
        $this->obligationKey = $obligationKey;
        $this->fulfilledAt = $fulfilledAt;
        $this->quantity = $quantity;
        $this->wearPercent = null === $wearPercent?->value() ? null : (float) $wearPercent->value();
        $this->note = $note;
        $this->fileId = $fileId;
        $this->manualDueDate = $manualDueDate;
        $this->returnedAt = $returnedAt;
        $this->returnedQuantity = $returnedQuantity;
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

    public function fileId(): ?string
    {
        return $this->fileId;
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
}
