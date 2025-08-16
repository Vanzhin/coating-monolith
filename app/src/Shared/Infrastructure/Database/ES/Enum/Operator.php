<?php
declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES\Enum;

enum Operator: string
{
    case MUST = 'must';
    case SHOULD = 'should';
    case FILTER = 'filter';
    case MUST_NOT = 'must_not';
}