<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document\ValueObject;

use App\Shared\Domain\Trait\EnumToArray;

enum DocumentCategoryType: string
{
    use EnumToArray;

    case PDS = 'Техническое описание';

    case SDS = 'Паспорт безопасности';

    case REGULATION = 'Регламент';

    case CERTIFICATE = 'Сертификат';

    case TESTING = 'Испытание';

    case SGR = 'СГР';

    case TECHNICAL_DOCUMENT = 'Технический документ';

    case PAINTING_ACT = 'Акт окраски';

    case FEEDBACK = 'Отзыв';

    case REFERENCE = 'Референс';

    case GENERAL = 'Общий документ';

    public static function fromName(string $name)
    {
        return constant("self::$name");
    }
}