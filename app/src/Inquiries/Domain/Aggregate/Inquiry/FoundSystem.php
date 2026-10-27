<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Один элемент снимка ответа: id найденной системы + человекочитаемая подпись. Самодостаточен —
 * подпись заморожена, чтобы запись читалась без обращения к Coatings (даже если систему удалят).
 */
final readonly class FoundSystem implements \JsonSerializable
{
    public function __construct(public string $id, public string $label)
    {
    }

    /** @return array{id: string, label: string} */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'label' => $this->label];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = isset($data['id']) && is_scalar($data['id']) ? (string) $data['id'] : '';
        $label = isset($data['label']) && is_scalar($data['label']) ? (string) $data['label'] : '';

        return new self($id, $label);
    }
}
