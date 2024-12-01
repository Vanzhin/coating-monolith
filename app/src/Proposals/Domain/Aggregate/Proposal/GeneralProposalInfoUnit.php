<?php

namespace App\Proposals\Domain\Aggregate\Proposal;

/**
 * Типа покрытия по назначению
 */
enum GeneralProposalInfoUnit: string
{
    case LITER = 'Литр';

    case KG = 'Килограмм';
}
