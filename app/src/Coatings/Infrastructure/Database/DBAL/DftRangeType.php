<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class DftRangeType extends AbstractJsonObjectType
{
    public const NAME = 'dft_range';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return DftRange::class;
    }

    protected function hydrate(array $raw): DftRange
    {
        return DftRange::fromArray($raw);
    }
}
