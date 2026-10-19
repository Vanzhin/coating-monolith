<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;

/**
 * Материальная позиция: инвентарь, выдаётся в количестве, ведётся остаток (СИЗ) — списанию подлежит только
 * она ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::writeOffEligibleFactsForRequirement}).
 * Количество обязательно — без него не собрать.
 */
final readonly class MaterialItem extends AbstractRequirementItem implements QuantifiedItem
{
    public function __construct(
        string $id,
        string $label,
        Cadence $cadence,
        string $basis,
        private Quantity $quantity,
    ) {
        parent::__construct($id, $label, $cadence, $basis);
    }

    public function type(): ComplianceType
    {
        return ComplianceType::Material;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    /** @return array{id: string, label: string, cadence: array{kind: string, number: int|null}, basis: string, quantity: array{amount: float, unit: string}} */
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
        );
    }
}
