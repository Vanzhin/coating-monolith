<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document\ValueObject;

use App\Shared\Domain\Trait\EnumToArray;

enum DocumentCategoryType: string
{
    use EnumToArray;

    case PDS = 'Техническое описание';

    case TECHNICAL_INSTRUCTION = 'Техническая инструкция';

    case REGULATION = 'Регламент';

    case TECHNICAL_DOCUMENT = 'Технический документ';

    case TESTING = 'Климатические сертификаты';

    case CERTIFICATE = 'Сертификаты соответствия и протоколы ОГЗ';

    case SGR = 'СГР';

    case PAINTING_ACT = 'Акт окраски';

    case FEEDBACK = 'Отзыв';

    case REFERENCE = 'Референс';

    case SDS = 'Паспорт безопасности';

    case GENERAL = 'Общий документ';

    case SPREADING_RATE = 'Расход';

    case COMPATIBLE = 'Таблица совместимости';

    public static function fromName(string $name)
    {
        return constant("self::$name");
    }
}