<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Проверка инженером. Существует ⟺ запрос в статусе Approved. Правки инженера не хранятся диффом —
 * финальные recognizedParameters уже и есть его версия (а след вмешательства — strategy = Manual
 * в ParameterSource). verifiedBy — ulid инженера (null для системного авто-одобрения в будущем).
 */
final readonly class Verification implements \JsonSerializable
{
    public function __construct(public ?string $verifiedBy, public \DateTimeImmutable $verifiedAt)
    {
    }

    /** @return array{verifiedBy: string|null, verifiedAt: string} */
    public function jsonSerialize(): array
    {
        return [
            'verifiedBy' => $this->verifiedBy,
            'verifiedAt' => $this->verifiedAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $verifiedBy = isset($data['verifiedBy']) && is_string($data['verifiedBy']) ? $data['verifiedBy'] : null;
        $rawAt = $data['verifiedAt'] ?? null;
        $verifiedAt = new \DateTimeImmutable(is_string($rawAt) ? $rawAt : 'now');

        return new self($verifiedBy, $verifiedAt);
    }
}
