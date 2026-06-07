<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Infrastructure\Exception\AppException;

class DftRange
{
    public function __construct(
        public PositiveNumberRange $range,
        public int $tdsDft,
        public ThicknessType $type = ThicknessType::MIC,
    )
    {
        if (!$range->isWithin($tdsDft)) {
            throw new AppException(sprintf(
                'Целевая толщина (tdsDft=%d) должна быть в диапазоне [%s, %s].',
                $tdsDft,
                $range->getMin(),
                $range->getMax()
            ));
        }
    }

}