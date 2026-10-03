<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Shared\Domain\ValueObject\Commission;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/** DBAL-тип комиссии акта списания ({@see Commission}) — jsonb; nullable (до подписания акта нет). */
final class WriteOffCommissionType extends AbstractJsonObjectType
{
    public const NAME = 'compliance_writeoff_commission';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    protected function valueClass(): string
    {
        return Commission::class;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function hydrate(array $raw): Commission
    {
        return Commission::fromArray($raw);
    }
}
