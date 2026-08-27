<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Document;

/**
 * Тип владельца, к которому прикручен документ (полиморфная мягкая ссылка).
 */
enum ReferenceType: string
{
    case CoatingSystem = 'coating_system';
    case Coating = 'coating';

    public function label(): string
    {
        return match ($this) {
            self::CoatingSystem => 'Система покрытий',
            self::Coating => 'Покрытие',
        };
    }
}
