<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Размеры сотрудника для подбора СИЗ — положительные числа (целые или дробные, «44», «4,5»).
 * Инвариант положительности — в PositiveNumber; здесь только парсинг ввода (запятая/точка → число)
 * и терпимое чтение из БД (легаси-мусор → null, чтобы не ронять загрузку профиля).
 */
final readonly class Sizes implements \JsonSerializable
{
    public function __construct(
        public ?PositiveNumber $clothing = null,
        public ?PositiveNumber $shoes = null,
        public ?PositiveNumber $headgear = null,
        public ?PositiveNumber $respirator = null,
        public ?PositiveNumber $gloves = null,
        public ?PositiveNumber $height = null,
        public ?Gender $gender = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** Из сырых строк формы: «44» / «4,5» / пусто. Пусто → null; не число или ≤ 0 → AppException. */
    public static function fromInput(
        ?string $clothing = null,
        ?string $shoes = null,
        ?string $headgear = null,
        ?string $respirator = null,
        ?string $gloves = null,
        ?string $height = null,
        ?Gender $gender = null,
    ): self {
        return new self(
            self::parse($clothing, 'Размер одежды'),
            self::parse($shoes, 'Размер обуви'),
            self::parse($headgear, 'Размер головного убора'),
            self::parse($respirator, 'Размер респиратора'),
            self::parse($gloves, 'Размер перчаток'),
            self::parse($height, 'Рост'),
            $gender,
        );
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            self::stored($data['clothing'] ?? null),
            self::stored($data['shoes'] ?? null),
            self::stored($data['headgear'] ?? null),
            self::stored($data['respirator'] ?? null),
            self::stored($data['gloves'] ?? null),
            self::stored($data['height'] ?? null),
            isset($data['gender']) ? Gender::tryFrom((string) $data['gender']) : null,
        );
    }

    /** @return array<string, int|float|string|null> */
    public function jsonSerialize(): array
    {
        return [
            'clothing' => $this->clothing?->value(),
            'shoes' => $this->shoes?->value(),
            'headgear' => $this->headgear?->value(),
            'respirator' => $this->respirator?->value(),
            'gloves' => $this->gloves?->value(),
            'height' => $this->height?->value(),
            'gender' => $this->gender?->value,
        ];
    }

    /** Ввод из формы: строгий — не число (кроме пустого) → человекочитаемая ошибка. */
    private static function parse(?string $value, string $label): ?PositiveNumber
    {
        if (null === $value) {
            return null;
        }
        $normalized = str_replace(',', '.', trim($value));
        if ('' === $normalized) {
            return null;
        }
        if (!is_numeric($normalized)) {
            throw new AppException(sprintf('%s: введите число (например, 44 или 4,5).', $label));
        }

        return new PositiveNumber(str_contains($normalized, '.') ? (float) $normalized : (int) $normalized);
    }

    /** Чтение из БД: терпимое — нечисловое/≤ 0 (легаси) → null, без падения на загрузке. */
    private static function stored(mixed $value): ?PositiveNumber
    {
        if (!is_numeric($value)) {
            return null;
        }
        $number = 0 + $value;

        return $number > 0 ? new PositiveNumber($number) : null;
    }
}
