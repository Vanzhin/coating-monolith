<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\WriteOffAct;

use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Строка акта списания — порция конкретного факта-выдачи ({@see $recordId}), принадлежащего профилю
 * ({@see $profileId}). Акт кросс-персон, поэтому каждая строка несёт свой профиль и снимок названия позиции
 * ({@see $labelSnapshot}, взят с факта на момент добавления — переименование позиции в норме потом акт не рвёт).
 */
class WriteOffActLine
{
    private readonly Uuid $id;
    private string $profileId;
    private string $recordId;
    private string $labelSnapshot;
    private float $quantity;
    private ?WriteOffReason $reason;
    /**
     * Доктрина: owning-сторона one-to-many (строка владеет FK `write_off_act_id`). Акт-владелец проставляет его
     * при создании строки ({@see WriteOffAct::addLinesForProfile}, {@see WriteOffAct::replacePortions}).
     */
    private ?WriteOffAct $writeOffAct = null;

    public function __construct(
        Uuid $id,
        string $profileId,
        string $recordId,
        string $labelSnapshot,
        float $quantity,
        ?WriteOffReason $reason = null,
        ?WriteOffAct $writeOffAct = null,
    ) {
        if ($quantity <= 0) {
            throw new AppException('Количество списания должно быть больше нуля.');
        }
        $this->id = $id;
        $this->profileId = $profileId;
        $this->recordId = $recordId;
        $this->labelSnapshot = $labelSnapshot;
        $this->quantity = $quantity;
        $this->reason = $reason;
        $this->writeOffAct = $writeOffAct;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function profileId(): string
    {
        return $this->profileId;
    }

    public function recordId(): string
    {
        return $this->recordId;
    }

    public function labelSnapshot(): string
    {
        return $this->labelSnapshot;
    }

    public function quantity(): float
    {
        return $this->quantity;
    }

    public function reason(): ?WriteOffReason
    {
        return $this->reason;
    }

    public function writeOffAct(): ?WriteOffAct
    {
        return $this->writeOffAct;
    }
}
