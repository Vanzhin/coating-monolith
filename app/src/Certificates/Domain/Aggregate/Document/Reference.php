<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Document;

use Symfony\Component\Uid\Uuid;

/**
 * Мягкая полиморфная ссылка документа на владельца (система/покрытие).
 * Равенство — по значению (тип + id). Хранится в jsonb-коллекции документа.
 */
final readonly class Reference implements \JsonSerializable
{
    public function __construct(
        public ReferenceType $referenceType,
        public Uuid $referenceId,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->referenceType === $other->referenceType
            && $this->referenceId->equals($other->referenceId);
    }

    /**
     * @return array{type: string, id: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => $this->referenceType->value,
            'id' => (string) $this->referenceId,
        ];
    }

    /**
     * @param array{type: string, id: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ReferenceType::from($data['type']),
            Uuid::fromString($data['id']),
        );
    }
}
