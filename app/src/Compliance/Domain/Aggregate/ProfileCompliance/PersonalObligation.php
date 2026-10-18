<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\ProfileCompliance;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Quantity;

/**
 * Персональная (off-norm) позиция требования — заведена прямо на карточке человека явной кнопкой на форме
 * выдачи, не входит в живую норму {@see \App\Compliance\Domain\Aggregate\Requirement\Requirement} (Вариант B:
 * агрегат не материализует копию нормы, только то, что нормой не покрыто). Хранится списком на агрегате
 * ({@see ProfileCompliance::personalObligations()}) как JSONB — зеркалит хранение позиций нормы
 * ({@see \App\Compliance\Infrastructure\Database\DBAL\RequirementItemsType}), но без полиморфизма (класс один).
 * Ключ обязанности — {@see \App\Compliance\Domain\ValueObject\ObligationKey::keyOf} от той же пары
 * (requirementId, itemId), что у норменных позиций.
 */
final readonly class PersonalObligation implements \JsonSerializable
{
    public function __construct(
        public string $itemId,
        public string $requirementId,
        public string $label,
        public ComplianceType $type,
        public Cadence $cadence,
        public ?Quantity $quantity,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['itemId'],
            (string) $data['requirementId'],
            (string) $data['label'],
            ComplianceType::from((string) $data['type']),
            Cadence::fromArray(is_array($data['cadence'] ?? null) ? $data['cadence'] : []),
            isset($data['quantity']) && is_array($data['quantity']) ? Quantity::fromArray($data['quantity']) : null,
        );
    }

    /**
     * @return array{itemId: string, requirementId: string, label: string, type: string,
     *     cadence: array{kind: string, number: int|null, unit: string|null},
     *     quantity: array{amount: float, unit: string}|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'itemId' => $this->itemId,
            'requirementId' => $this->requirementId,
            'label' => $this->label,
            'type' => $this->type->value,
            'cadence' => $this->cadence->jsonSerialize(),
            'quantity' => $this->quantity?->jsonSerialize(),
        ];
    }
}
