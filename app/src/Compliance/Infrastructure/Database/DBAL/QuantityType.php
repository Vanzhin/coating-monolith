<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\ValueObject\Quantity;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class QuantityType extends AbstractJsonObjectType
{
    public const NAME = 'compliance_quantity';

    protected function valueClass(): string
    {
        return Quantity::class;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function hydrate(array $raw): Quantity
    {
        return Quantity::fromArray($raw);
    }
}
