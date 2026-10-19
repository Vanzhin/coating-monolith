<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;

/**
 * Периодическая позиция: единая позиция требования, которое выдаётся/проходится раз в N и всегда
 * в количестве (журнал: пройти инструктаж 1 раз, выдать мыло 1200 мл). Количество обязательно — без
 * него не собрать. В отличие от {@see MaterialItem} у позиции нет режима (инвентарный/расходный):
 * периодическая позиция не ведёт остаток, контроль только по дате следующего прохождения.
 */
final readonly class PeriodicItem extends AbstractRequirementItem implements QuantifiedItem
{
    public function __construct(
        string $id,
        string $label,
        Cadence $cadence,
        string $basis,
        private Quantity $quantity,
        bool $removed = false,
    ) {
        parent::__construct($id, $label, $cadence, $basis, $removed);
    }

    public function type(): ComplianceType
    {
        return ComplianceType::Periodic;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function withRemoved(): self
    {
        return new self($this->id(), $this->label(), $this->cadence(), $this->basis(), $this->quantity, true);
    }

    /** @return array{id: string, label: string, cadence: array{kind: string, number: int|null}, basis: string, removed?: true, quantity: array{amount: float, unit: string}} */
    public function jsonSerialize(): array
    {
        return [...$this->baseArray(), 'quantity' => $this->quantity->jsonSerialize()];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::readId($data),
            (string) ($data['label'] ?? ''),
            Cadence::fromArray(is_array($data['cadence'] ?? null) ? $data['cadence'] : []),
            (string) ($data['basis'] ?? ''),
            Quantity::fromArray(is_array($data['quantity'] ?? null) ? $data['quantity'] : []),
            self::readRemoved($data),
        );
    }
}
