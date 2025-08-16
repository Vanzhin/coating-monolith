<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES\Enum;

enum Type: string
{
    case MATCH = 'match';
    case TERM = 'term';
    case RANGE = 'range';
    case EXISTS = 'exists';
    case WILDCARD = 'wildcard';
    case REGEXP = 'regexp';
    case BOOL = 'bool';
}