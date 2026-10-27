<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Database\DBAL;

use App\Inquiries\Domain\Aggregate\Inquiry\Verification;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class VerificationType extends AbstractJsonObjectType
{
    public const NAME = 'inquiry_verification';

    protected function valueClass(): string
    {
        return Verification::class;
    }

    protected function hydrate(array $raw): object
    {
        return Verification::fromArray($raw);
    }
}
