<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Database\DBAL;

use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

/**
 * Хранит ФИО сотрудника как JSON. Регистрируется как тип `personnel_full_name` в doctrine.yaml.
 */
final class FullNameType extends AbstractJsonObjectType
{
    protected function valueClass(): string
    {
        return FullName::class;
    }

    protected function hydrate(array $raw): FullName
    {
        return FullName::fromArray($raw);
    }
}
