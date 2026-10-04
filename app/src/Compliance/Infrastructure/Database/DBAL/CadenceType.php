<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\ValueObject\Cadence;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class CadenceType extends AbstractJsonObjectType
{
    public const NAME = 'compliance_cadence';

    protected function valueClass(): string
    {
        return Cadence::class;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function hydrate(array $raw): Cadence
    {
        return Cadence::fromArray($raw);
    }
}
