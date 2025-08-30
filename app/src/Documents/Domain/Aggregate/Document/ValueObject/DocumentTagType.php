<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document\ValueObject;

use App\Shared\Domain\Trait\EnumToArray;

enum DocumentTagType: string
{
    use EnumToArray;

    case PTM = 'приведенная толщина металла';
    case FRL = 'предел огнестойкости';

    case DEFAULT = 'общий';

    public static function fromName(string $name)
    {
        $name = mb_strtoupper($name);
        return constant("self::$name");
    }
}