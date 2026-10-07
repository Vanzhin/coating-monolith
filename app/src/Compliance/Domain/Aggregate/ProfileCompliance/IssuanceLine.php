<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use Symfony\Component\Uid\Uuid;

/** Одна строка оформляемой выдачи (позиция + сколько выдано). Собирается в Application из формы. */
final readonly class IssuanceLine
{
    public function __construct(
        public Uuid $recordId,
        public string $obligationKey,
        public \DateTimeImmutable $fulfilledAt,
        public ?Quantity $quantity = null,
        public ?Percent $wearPercent = null,
        public ?\DateTimeImmutable $manualDueDate = null,
        public ?string $note = null,
        public ?InstructionDetails $instructionDetails = null,
    ) {
    }
}
