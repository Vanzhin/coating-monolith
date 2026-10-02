<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\ValueObject\WriteOffCommission;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/** DBAL-тип комиссии акта списания ({@see WriteOffCommission}) — jsonb; nullable (до подписания акта нет). */
final class WriteOffCommissionType extends AbstractJsonObjectType
{
    public const NAME = 'compliance_writeoff_commission';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    protected function valueClass(): string
    {
        return WriteOffCommission::class;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function hydrate(array $raw): WriteOffCommission
    {
        return WriteOffCommission::fromArray($raw);
    }
}
