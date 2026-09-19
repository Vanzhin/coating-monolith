<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;

/**
 * Фотографии отчёта. Опциональный блок: поле не обязательно, нет фото → нет секции на выходе
 * (presence-driven проекция + опциональный регион шаблона). Каждое фото — uuid хранёного файла
 * (FileStorage) + подпись. Файл грузится онлайн (stage), привязывается к отчёту на сохранении (promote).
 */
final class PhotosBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::Photos;
    }

    public function title(): string
    {
        return 'Фотографии';
    }

    public function fields(): array
    {
        return [
            new Field('items', FieldType::PhotoSlot, 'Фотографии', itemFields: [
                new Field('file', FieldType::Text, 'Файл', required: true), // uuid хранёного файла
                new Field('caption', FieldType::Text, 'Подпись'),
            ]),
        ];
    }
}
