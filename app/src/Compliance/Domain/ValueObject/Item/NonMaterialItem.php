<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;

/**
 * Нематериальная позиция: событие без количества (инструктаж, медосмотр). Поля количества нет вообще.
 */
final readonly class NonMaterialItem extends AbstractRequirementItem
{
    public function type(): ComplianceType
    {
        return ComplianceType::NonMaterial;
    }

    /** @return array{label: string, cadence: array{kind: string, number: int|null}, basis: string} */
    public function jsonSerialize(): array
    {
        return $this->baseArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['label'] ?? ''),
            Cadence::fromArray(is_array($data['cadence'] ?? null) ? $data['cadence'] : []),
            (string) ($data['basis'] ?? ''),
        );
    }
}
