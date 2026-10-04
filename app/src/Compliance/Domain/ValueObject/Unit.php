<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

/** Единица измерения количества СИЗ. Набор стартовый, расширяется кейсом. */
enum Unit: string
{
    case Piece = 'pcs';
    case Pair = 'pair';
    case Set = 'set';
    case Milliliter = 'ml';
    case Gram = 'g';

    public function title(): string
    {
        return match ($this) {
            self::Piece => 'шт.',
            self::Pair => 'пара',
            self::Set => 'комплект',
            self::Milliliter => 'мл',
            self::Gram => 'г',
        };
    }
}
