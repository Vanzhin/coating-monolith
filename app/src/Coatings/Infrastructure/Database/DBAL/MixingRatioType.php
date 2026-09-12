<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\MixingRatio;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class MixingRatioType extends AbstractJsonObjectType
{
    public const NAME = 'mixing_ratio';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return MixingRatio::class;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function hydrate(array $raw): MixingRatio
    {
        return MixingRatio::fromArray($raw);
    }
}
