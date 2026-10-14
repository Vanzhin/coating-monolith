<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\MaterialMode;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;

/**
 * Материальная позиция: выдаётся в количестве (СИЗ). Количество обязательно — без него не собрать.
 * Режим ({@see MaterialMode}) различает инвентарную (ведётся остаток, подлежит списанию)
 * и расходную (контроль по дате, без учёта остатка) — по умолчанию инвентарная.
 */
final readonly class MaterialItem extends AbstractRequirementItem
{
    public function __construct(
        string $id,
        string $label,
        Cadence $cadence,
        string $basis,
        private Quantity $quantity,
        private MaterialMode $mode = MaterialMode::Inventoried,
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

    public function mode(): MaterialMode
    {
        return $this->mode;
    }

    /** @return array{id: string, label: string, cadence: array{kind: string, number: int|null}, basis: string, quantity: array{amount: float, unit: string}, mode: string} */
    public function jsonSerialize(): array
    {
        return [...$this->baseArray(), 'quantity' => $this->quantity->jsonSerialize(), 'mode' => $this->mode->value];
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
            MaterialMode::tryFrom((string) ($data['mode'] ?? '')) ?? MaterialMode::Inventoried,
        );
    }
}
