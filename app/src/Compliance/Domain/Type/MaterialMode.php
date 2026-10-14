<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/**
 * Режим материальной позиции. Инвентарная (обычные СИЗ): ведётся остаток, контроль по остатку vs норма,
 * подлежит списанию. Расходная (ДСИЗ — крема/мыло): остаток не ведём, контроль по дате (как инструктаж),
 * списанию не подлежит. Количество (Quantity) есть у обеих — режим управляет только учётом остатка и списанием.
 * Новый режим = новый case; ядро не трогаем (паттерн {@see ComplianceType}).
 */
enum MaterialMode: string
{
    case Inventoried = 'inventoried';
    case Consumable = 'consumable';

    public function title(): string
    {
        return match ($this) {
            self::Inventoried => 'инвентарь',
            self::Consumable => 'расходник',
        };
    }

    /** Остаток не ведём, контроль по дате. */
    public function isConsumable(): bool
    {
        return self::Consumable === $this;
    }

    /** Подлежит списанию (акт списания) только инвентарная. */
    public function writeOffApplicable(): bool
    {
        return self::Inventoried === $this;
    }
}
