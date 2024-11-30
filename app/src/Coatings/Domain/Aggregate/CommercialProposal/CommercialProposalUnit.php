<?php

namespace App\Coatings\Domain\Aggregate\CommercialProposal;

/**
 * Типа покрытия по назначению
 */
enum CommercialProposalUnit: string
{
    case LITER = 'Литр';

    case KG = 'Килограмм';
}
