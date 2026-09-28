<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Database\DBAL;

use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

/**
 * Хранит размеры сотрудника для подбора СИЗ как JSON. Регистрируется как тип `personnel_sizes`
 * в doctrine.yaml.
 */
final class SizesType extends AbstractJsonObjectType
{
    protected function valueClass(): string
    {
        return Sizes::class;
    }

    protected function hydrate(array $raw): Sizes
    {
        return Sizes::fromArray($raw);
    }
}
