<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/** Вид поля схемы журнала — как его рисовать в форме и чем заполнять. */
enum FieldKind: string
{
    case Text = 'text';
    case Date = 'date';
    case Select = 'select';
    case TextList = 'text_list';
}
