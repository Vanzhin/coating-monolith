<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Database\DBAL;

use App\Reports\Domain\Aggregate\Report\Reference;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

/**
 * Хранит снимок-ссылку отчёта {id,title} как JSON. null-колонка = ссылки нет.
 * Регистрируется как тип `reports_reference` в doctrine.yaml.
 */
final class ReferenceType extends AbstractJsonObjectType
{
    protected function valueClass(): string
    {
        return Reference::class;
    }

    protected function hydrate(array $raw): Reference
    {
        return Reference::fromArray($raw);
    }
}
