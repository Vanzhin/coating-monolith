<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block;

/**
 * Тип поля блока. Скаляры валидируются/проецируются напрямую; ссылки — на справочники (снимок+id);
 * композиты — вложенные структуры (Layers — таблица слоёв, ListRows — повторяемые строки);
 * медиа — PhotoSlot (список фото {file: uuid, caption} через FileStorage). ProductRef пока не задействован.
 */
enum FieldType: string
{
    case Text = 'text';
    case TextArea = 'textarea';
    case Number = 'number';
    case Date = 'date';
    case TimeRange = 'timerange';
    case Bool = 'bool';
    case Enum = 'enum';
    case CoatingRef = 'coating_ref';
    case ColorRef = 'color_ref';
    case ProductRef = 'product_ref';
    case Thickness = 'thickness';
    case NumberRange = 'number_range';
    case Thinner = 'thinner';
    case DateTimeRange = 'datetime_range';
    case Layers = 'layers';
    case ListRows = 'list';
    case StringList = 'string_list';
    case PhotoSlot = 'photo_slot';

    public function isScalar(): bool
    {
        return in_array($this, [
            self::Text, self::TextArea, self::Number, self::Date, self::TimeRange, self::Bool, self::Enum,
        ], true);
    }
}
