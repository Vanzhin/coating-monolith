<?php

namespace App\Shared\Infrastructure\Database\ES;

enum Type: string
{
    case RANGE = 'range';

    case MATCH = 'match';

    case TERM = 'term';
}
