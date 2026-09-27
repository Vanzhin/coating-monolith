<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Схема обогрева профиля — какие грани сечения контактируют с огнём. Определяет обогреваемый
 * периметр, а значит и ПТМ: колонна обогревается со всех сторон (4), балка под плитой — с трёх
 * (верх закрыт плитой). Каждый ProfileSection трактует грани по своей геометрии.
 */
final readonly class HeatingScheme
{
    public function __construct(
        public bool $top,
        public bool $bottom,
        public bool $left,
        public bool $right,
    ) {
        if (!$top && !$bottom && !$left && !$right) {
            throw new AppException('Укажите хотя бы одну обогреваемую сторону профиля.');
        }
    }

    /** Обогрев со всех сторон (колонна). */
    public static function allSides(): self
    {
        return new self(true, true, true, true);
    }

    /** Три стороны — верх закрыт (балка под плитой перекрытия). */
    public static function threeSided(): self
    {
        return new self(false, true, true, true);
    }
}
