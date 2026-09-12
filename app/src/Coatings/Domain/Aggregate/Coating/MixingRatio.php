<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Соотношение смешивания компонентов многокомпонентного покрытия. Держит не сами
 * компоненты, а их пропорцию — по объёму и/или по массе (обе базы опциональны, но
 * хотя бы одна должна быть задана). Нужно при приготовлении рабочей смеси; расчёт
 * дозировок делегируется нужной базе (PartsRatio).
 *
 * У однокомпонентного покрытия соотношения нет — на агрегате Coating поле nullable.
 *
 * Хранение — JSONB через MixingRatioType (Doctrine DBAL).
 */
final readonly class MixingRatio implements \JsonSerializable
{
    public function __construct(
        public ?PartsRatio $byVolume = null,
        public ?PartsRatio $byMass = null,
    ) {
        if (null === $byVolume && null === $byMass) {
            throw new AppException('Соотношение компонентов: задайте хотя бы одну базу — объёмную или массовую.');
        }
        if (null !== $byVolume && null !== $byMass && $byVolume->count() !== $byMass->count()) {
            throw new AppException('Объёмное и массовое соотношения описывают один материал — число компонентов должно совпадать.');
        }
    }

    public function getByVolume(): ?PartsRatio
    {
        return $this->byVolume;
    }

    public function getByMass(): ?PartsRatio
    {
        return $this->byMass;
    }

    /**
     * @param array{volume?: list<int|float>|null, mass?: list<int|float>|null} $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            self::partsFrom($raw['volume'] ?? null),
            self::partsFrom($raw['mass'] ?? null),
        );
    }

    /**
     * @param list<int|float>|null $parts
     */
    private static function partsFrom(?array $parts): ?PartsRatio
    {
        if (null === $parts) {
            return null;
        }

        return new PartsRatio(...array_map(static fn ($part): PositiveNumber => new PositiveNumber((float) $part), $parts));
    }

    /**
     * @return array{volume: list<float>|null, mass: list<float>|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'volume' => $this->byVolume?->getParts(),
            'mass' => $this->byMass?->getParts(),
        ];
    }
}
