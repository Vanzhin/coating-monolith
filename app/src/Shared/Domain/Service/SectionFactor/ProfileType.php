<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

/**
 * Тип стального профиля, для которого считается приведённая толщина металла.
 * Value совпадает с ключом типа в сортаменте (sortament.json) и на фронте.
 */
enum ProfileType: string
{
    case I_BEAM = 'i_beam';
    case CHANNEL = 'channel';
    case ANGLE = 'angle';
    case PIPE = 'pipe';
    case ROUND_BAR = 'round_bar';
    case RECT_HOLLOW = 'rect_hollow';
    case SHEET = 'sheet';

    public function label(): string
    {
        return match ($this) {
            self::I_BEAM => 'Двутавр',
            self::CHANNEL => 'Швеллер',
            self::ANGLE => 'Уголок',
            self::PIPE => 'Труба',
            self::ROUND_BAR => 'Круг',
            self::RECT_HOLLOW => 'Профиль',
            self::SHEET => 'Лист',
        };
    }
}
