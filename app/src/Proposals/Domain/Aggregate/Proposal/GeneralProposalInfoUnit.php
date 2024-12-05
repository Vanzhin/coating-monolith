<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Типа покрытия по назначению
 */
enum GeneralProposalInfoUnit: string
{
    use EnumToArray;

    case LITER = 'Литр';

    case KG = 'Килограмм';


}
