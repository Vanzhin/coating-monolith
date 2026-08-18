<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Color;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Запись каталога RAL Classic: код («RAL 7040»), каноничное имя и эталонный HEX.
 * Эталон hex — источник истины для инварианта «цвет с RAL не расходится со своим RAL».
 */
final readonly class RalColor
{
    public function __construct(
        public string $code,
        public string $name,
        public HexColor $hex,
    ) {
        if (!preg_match('/^RAL \d{4}$/', $code)) {
            throw new AppException(sprintf('Некорректный код RAL: «%s». Ожидается формат «RAL 7040».', $code));
        }

        if ('' === trim($name)) {
            throw new AppException('Имя RAL-цвета не может быть пустым.');
        }
    }
}
