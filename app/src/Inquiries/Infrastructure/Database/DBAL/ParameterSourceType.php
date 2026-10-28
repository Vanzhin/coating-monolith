<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Database\DBAL;

use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class ParameterSourceType extends AbstractJsonObjectType
{
    public const NAME = 'inquiry_parameter_source';

    protected function valueClass(): string
    {
        return ParameterSource::class;
    }

    protected function hydrate(array $raw): object
    {
        return ParameterSource::fromArray($raw);
    }
}
