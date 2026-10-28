<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Откуда взялись распознанные параметры. Model — извлекла модель; Cache — переиспользованы из
 * одобренного похожего запроса (модель не звали); Manual — поправил инженер.
 */
enum ParameterStrategy: string
{
    case Model = 'model';
    case Cache = 'cache';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Model => 'Модель',
            self::Cache => 'Кэш',
            self::Manual => 'Вручную',
        };
    }
}
