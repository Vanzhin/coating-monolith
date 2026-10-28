<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Database\DBAL;

use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class RecognizedParametersType extends AbstractJsonObjectType
{
    public const NAME = 'inquiry_recognized_parameters';

    protected function valueClass(): string
    {
        return RecognizedParameters::class;
    }

    protected function hydrate(array $raw): object
    {
        return RecognizedParameters::fromArray($raw);
    }
}
