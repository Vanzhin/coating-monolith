<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\WriteOffReason;
use Symfony\Component\Uid\Uuid;

/**
 * Порция списания — сколько конкретного факта-выдачи ({@see $recordId}) списано в этом акте ({@see WriteOffAct}) и
 * почему. Один факт может иметь порции в нескольких актах (частями во времени). Наименование/дату тянем из факта.
 */
class WriteOffItem
{
    private readonly Uuid $id;
    private WriteOffAct $writeOffAct;
    private string $recordId;
    private float $quantity;
    private ?WriteOffReason $reason;

    public function __construct(Uuid $id, WriteOffAct $writeOffAct, string $recordId, float $quantity, ?WriteOffReason $reason = null)
    {
        $this->id = $id;
        $this->writeOffAct = $writeOffAct;
        $this->recordId = $recordId;
        $this->quantity = $quantity;
        $this->reason = $reason;
    }

    public function addQuantity(float $amount): void
    {
        $this->quantity += $amount;
    }

    public function setReason(?WriteOffReason $reason): void
    {
        $this->reason = $reason;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    public function quantity(): float
    {
        return $this->quantity;
    }

    public function reason(): ?WriteOffReason
    {
        return $this->reason;
    }

    public function writeOffAct(): WriteOffAct
    {
        return $this->writeOffAct;
    }
}
