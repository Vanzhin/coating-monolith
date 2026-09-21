<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\Mapping;

/** Откуда берётся значение переменной шаблона: реквизит-шапка или поле блока. */
enum TemplateSource: string
{
    case Header = 'header';
    case Field = 'field';
}
