<?php

namespace App\Shared\Infrastructure\Database\ES;


enum QueryParamType: string
{
    case MUST = 'must';

    case SHOULD = 'should';

    case FILTER = 'filter';

    case MUST_NOT = 'must_not';

}
