<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

/**
 * Размеры сотрудника для подбора СИЗ. Значения свободной формы (строка: «52-54», «10.5») —
 * без бизнес-инвариантов, только нормализация пустых строк в null.
 */
final readonly class Sizes implements \JsonSerializable
{
    public ?string $clothing;
    public ?string $shoes;
    public ?string $headgear;
    public ?string $gasMask;
    public ?string $respirator;
    public ?string $gloves;
    public ?string $height;
    public ?Gender $gender;

    public function __construct(
        ?string $clothing = null,
        ?string $shoes = null,
        ?string $headgear = null,
        ?string $gasMask = null,
        ?string $respirator = null,
        ?string $gloves = null,
        ?string $height = null,
        ?Gender $gender = null,
    ) {
        $this->clothing = self::normalize($clothing);
        $this->shoes = self::normalize($shoes);
        $this->headgear = self::normalize($headgear);
        $this->gasMask = self::normalize($gasMask);
        $this->respirator = self::normalize($respirator);
        $this->gloves = self::normalize($gloves);
        $this->height = self::normalize($height);
        $this->gender = $gender;
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['clothing']) ? (string) $data['clothing'] : null,
            isset($data['shoes']) ? (string) $data['shoes'] : null,
            isset($data['headgear']) ? (string) $data['headgear'] : null,
            isset($data['gasMask']) ? (string) $data['gasMask'] : null,
            isset($data['respirator']) ? (string) $data['respirator'] : null,
            isset($data['gloves']) ? (string) $data['gloves'] : null,
            isset($data['height']) ? (string) $data['height'] : null,
            isset($data['gender']) ? Gender::from((string) $data['gender']) : null,
        );
    }

    /**
     * @return array{
     *     clothing: string|null,
     *     shoes: string|null,
     *     headgear: string|null,
     *     gasMask: string|null,
     *     respirator: string|null,
     *     gloves: string|null,
     *     height: string|null,
     *     gender: string|null,
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'clothing' => $this->clothing,
            'shoes' => $this->shoes,
            'headgear' => $this->headgear,
            'gasMask' => $this->gasMask,
            'respirator' => $this->respirator,
            'gloves' => $this->gloves,
            'height' => $this->height,
            'gender' => $this->gender?->value,
        ];
    }

    private static function normalize(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' !== $trimmed ? $trimmed : null;
    }
}
