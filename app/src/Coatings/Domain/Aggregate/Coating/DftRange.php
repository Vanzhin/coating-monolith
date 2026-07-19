<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Infrastructure\Exception\AppException;

class DftRange implements \JsonSerializable
{
    public function __construct(
        public PositiveNumberRange $range,
        public int $tdsDft,
        public ThicknessType $type = ThicknessType::MIC,
    ) {
        if (!$range->isWithin($tdsDft)) {
            throw new AppException(sprintf('Целевая толщина (tdsDft=%d) должна быть в диапазоне [%s, %s].', $tdsDft, $range->getMin(), $range->getMax()));
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'min' => $this->range->getMin(),
            'max' => $this->range->getMax(),
            'tds_dft' => $this->tdsDft,
            'type' => $this->type->value,
        ];
    }

    public static function fromArray(array $raw): self
    {
        return new self(
            new PositiveNumberRange((int) $raw['min'], (int) $raw['max']),
            (int) $raw['tds_dft'],
            ThicknessType::from($raw['type']),
        );
    }
}
