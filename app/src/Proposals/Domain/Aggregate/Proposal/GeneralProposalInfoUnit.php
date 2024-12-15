<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Тип покрытия по назначению
 */
enum GeneralProposalInfoUnit: string
{
    use EnumToArray;

    case LITER = 'л';

    case KG = 'кг';


}
